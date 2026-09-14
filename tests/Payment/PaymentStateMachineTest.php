<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\PaymentStateMachine;
use App\Payment\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * 전이표의 **모든 칸**을 돈다.
 *
 * 표를 그대로 옮겨 적은 테스트가 무슨 의미냐는 반문이 가능한데, 여기서는
 * 의미가 있다 — 이 배열이 그대로 CAS 의 `WHERE status IN (…)` 이 되고,
 * 한 칸이 바뀌면 **중복 웹훅이나 순서 역전이 통과한다.** 그건 테스트가
 * 없으면 D-3 측정을 다시 돌려야만 드러난다.
 *
 * 그래서 표를 두 번 적는다. 아래 MATRIX 는 계획 2장 문서의 표를 옮긴
 * 것이고, PaymentStateMachine::TABLE 은 SQL 이 읽는 표다. **둘이 어긋나면
 * 여기서 깨진다.** 한쪽만 고치는 일을 막는 것이 이 중복의 값이다.
 */
final class PaymentStateMachineTest extends TestCase
{
    /**
     * from → to → 전이하는가.
     *
     * 세로가 현재 상태, 가로가 웹훅이 말하는 상태다.
     *
     * @var array<string, array<string, bool>>
     */
    private const MATRIX = [
        // created 는 전이의 목적지가 아니다 — /purchase 의 INSERT 로만 생긴다.
        'created' => [
            'created' => false, 'pending' => true, 'authorized' => true,
            'captured' => true, 'failed' => true, 'refunded' => false,
        ],
        'pending' => [
            'created' => false, 'pending' => false, 'authorized' => true,
            'captured' => true, 'failed' => true, 'refunded' => false,
        ],
        'authorized' => [
            'created' => false, 'pending' => false, 'authorized' => false,
            'captured' => true, 'failed' => true, 'refunded' => false,
        ],
        // captured 에서 앞으로 갈 곳은 환불뿐이다. pending·authorized 는
        // 과거라 무시되고, 이것이 순서 역전 방어의 전부다 → 계획 4장
        'captured' => [
            'created' => false, 'pending' => false, 'authorized' => false,
            'captured' => false, 'failed' => false, 'refunded' => true,
        ],
        // 종결. 어디로도 가지 않는다.
        'failed' => [
            'created' => false, 'pending' => false, 'authorized' => false,
            'captured' => false, 'failed' => false, 'refunded' => false,
        ],
        'refunded' => [
            'created' => false, 'pending' => false, 'authorized' => false,
            'captured' => false, 'failed' => false, 'refunded' => false,
        ],
    ];

    /** @dataProvider 전이표의모든칸 */
    public function test_전이표의_모든_칸(string $from, string $to, bool $expected): void
    {
        $machine = new PaymentStateMachine();

        self::assertSame(
            $expected,
            $machine->canTransition($from, $to),
            sprintf('%s → %s', $from, $to)
        );
    }

    public static function 전이표의모든칸(): array
    {
        $cells = [];

        foreach (self::MATRIX as $from => $row) {
            foreach ($row as $to => $expected) {
                $cells[$from.' → '.$to] = [$from, $to, $expected];
            }
        }

        return $cells;
    }

    /**
     * 표가 상태 목록 전체를 덮는가.
     *
     * 상태를 하나 늘리고 MATRIX 를 안 고치면 그 상태에 대한 칸이 통째로
     * 안 돌아간다 — 테스트가 늘 초록이라 늘어난 줄 모른다. 여기서 센다.
     */
    public function test_표는_상태_전체를_덮는다(): void
    {
        self::assertSame(PaymentStatus::ALL, array_keys(self::MATRIX));

        foreach (self::MATRIX as $from => $row) {
            self::assertSame(PaymentStatus::ALL, array_keys($row), $from.' 행');
        }

        self::assertCount(36, self::전이표의모든칸());
    }

    /**
     * `allowedFrom()` 이 돌려주는 배열은 **그대로 SQL 의 IN 목록**이다.
     * 순서까지 못박는다 — 쿼리 문자열이 실행마다 달라지면
     * 슬로우 로그에서 같은 쿼리를 하나로 묶어 보기 어렵다.
     */
    public function test_allowedFrom_이_SQL_의_IN_목록이_된다(): void
    {
        $machine = new PaymentStateMachine();

        self::assertSame(['created'], $machine->allowedFrom('pending'));
        self::assertSame(['created', 'pending'], $machine->allowedFrom('authorized'));
        self::assertSame(['created', 'pending', 'authorized'], $machine->allowedFrom('captured'));
        self::assertSame(['created', 'pending', 'authorized'], $machine->allowedFrom('failed'));
        self::assertSame(['captured'], $machine->allowedFrom('refunded'));
    }

    /**
     * 표에 없는 to 는 **빈 배열**이다.
     *
     * 호출자가 이걸 그대로 SQL 에 넣으면 `IN ()` 구문 오류가 난다.
     * 빈 배열을 먼저 거르는 책임이 호출자에게 있다는 것을 못박아 둔다
     * → Payment_model::applyEvent()
     */
    public function test_모르는_목적지는_빈_배열이다(): void
    {
        $machine = new PaymentStateMachine();

        self::assertSame([], $machine->allowedFrom('created'));
        self::assertSame([], $machine->allowedFrom('cancelled'));
        self::assertSame([], $machine->allowedFrom(''));
    }

    /**
     * **건너뛴 전진은 허용한다.** 이 표의 유일한 판단이다.
     *
     * `captured` 가 `authorized` 보다 먼저 도착했을 때 무시하면 우리는
     * 200 을 돌려주고, PG 는 재전송하지 않고, 결제 완료가 사라진다.
     */
    public function test_건너뛴_전진은_통과한다(): void
    {
        $machine = new PaymentStateMachine();

        self::assertTrue($machine->canTransition('created', 'captured'));
        self::assertTrue($machine->canTransition('created', 'authorized'));
        self::assertTrue($machine->canTransition('pending', 'captured'));
    }

    /** 과거가 미래를 덮지 못한다. 순서 역전 방어의 본체다. */
    public function test_역행은_막힌다(): void
    {
        $machine = new PaymentStateMachine();

        self::assertFalse($machine->canTransition('captured', 'pending'));
        self::assertFalse($machine->canTransition('captured', 'authorized'));
        self::assertFalse($machine->canTransition('authorized', 'pending'));
    }

    /**
     * 같은 상태로의 전이는 없다.
     *
     * 중복 수신 8건 중 7건이 0행으로 떨어지는 근거가 이 칸이다 —
     * `captured → captured` 가 true 가 되는 순간 코인이 여덟 배가 된다.
     */
    public function test_같은_상태로는_전이하지_않는다(): void
    {
        $machine = new PaymentStateMachine();

        foreach (PaymentStatus::ALL as $status) {
            self::assertFalse($machine->canTransition($status, $status), $status);
        }
    }

    public function test_종결_상태에서는_아무_데도_못_간다(): void
    {
        $machine = new PaymentStateMachine();

        foreach (['failed', 'refunded'] as $terminal) {
            self::assertTrue(PaymentStatus::isTerminal($terminal));

            foreach (PaymentStatus::ALL as $to) {
                self::assertFalse($machine->canTransition($terminal, $to), $terminal.' → '.$to);
            }
        }
    }

    public function test_표의_목적지_목록(): void
    {
        self::assertSame(
            ['pending', 'authorized', 'captured', 'failed', 'refunded'],
            (new PaymentStateMachine())->targets()
        );
    }
}
