<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\RefundPolicy;
use PHPUnit\Framework\TestCase;

final class RefundPolicyTest extends TestCase
{
    /** @dataProvider 도착 */
    public function test_캡처_전에_온_환불만_재시도를_요청한다(string $current, string $to, bool $expected): void
    {
        self::assertSame($expected, RefundPolicy::arrivedBeforeCapture($current, $to));
    }

    public static function 도착(): array
    {
        return [
            'created 에 환불'    => ['created', 'refunded', true],
            'pending 에 환불'    => ['pending', 'refunded', true],
            'authorized 에 환불' => ['authorized', 'refunded', true],
            'captured 에 환불'   => ['captured', 'refunded', false],
            '이미 환불됨'         => ['refunded', 'refunded', false],
            '실패한 결제에 환불'   => ['failed', 'refunded', false],
            '환불이 아닌 역전'     => ['authorized', 'pending', false],
        ];
    }

    public function test_남은_만큼만_회수하고_쓴_만큼을_알린다(): void
    {
        self::assertSame(['revoke' => 100, 'spent' => 0, 'already' => false], RefundPolicy::revocation(100, 100));
        self::assertSame(['revoke' => 70, 'spent' => 30, 'already' => false], RefundPolicy::revocation(100, 70));
        self::assertSame(['revoke' => 0, 'spent' => 100, 'already' => false], RefundPolicy::revocation(100, 0));
    }

    public function test_잔액이_이상해도_음수로_회수하지_않는다(): void
    {
        self::assertSame(['revoke' => 0, 'spent' => 100, 'already' => false], RefundPolicy::revocation(100, -5));
        self::assertSame(['revoke' => 100, 'spent' => 0, 'already' => false], RefundPolicy::revocation(100, 120));
    }

    public function test_이미_회수한_lot_은_다시_계산하지_않는다(): void
    {
        // 첫 회수는 remaining 을 0 으로 만든다. 그 lot 을 다시 계산하면
        // "100 을 전부 썼다" 가 된다 — 실제로는 하나도 안 썼는데.
        $first = RefundPolicy::revocation(100, 100);
        $second = RefundPolicy::revocation(100, 0, alreadyRevoked: true);

        self::assertSame(['revoke' => 100, 'spent' => 0, 'already' => false], $first);
        self::assertSame(['revoke' => 0, 'spent' => 0, 'already' => true], $second);
    }

    public function test_회수_표시가_없으면_남은_0_은_사용으로_읽힌다_그래서_표시가_필요하다(): void
    {
        // 표시 없이 두 번째 회수를 하면 이렇게 된다. 이 테스트가 깨지면
        // 위의 멱등 처리가 왜 있는지 다시 봐야 한다.
        self::assertSame(100, RefundPolicy::revocation(100, 0)['spent']);
    }
}
