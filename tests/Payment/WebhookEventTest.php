<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * 본문 검증 — **거절 사유별로** 센다.
 *
 * 여기서 거절되는 것은 전부 422 이고, 422 는 PG 에게 "재전송해도 같다"
 * 는 뜻이다. 그러니 재전송하면 달라질 수 있는 것이 이 목록에 섞이면
 * 안 된다 — 모르는 payment_uid 는 404 지 422 가 아니다(계획 1장).
 */
final class WebhookEventTest extends TestCase
{
    private const UID = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';

    private static function body(array $overrides = []): array
    {
        return $overrides + [
            'event_id' => 'evt_01J8XM',
            'payment_uid' => self::UID,
            'status' => 'captured',
        ];
    }

    public function test_본문을_통째로_받는다(): void
    {
        $event = WebhookEvent::fromBody([
            'event_id' => 'evt_01J8XM',
            'payment_uid' => self::UID,
            'status' => 'captured',
            'amount_minor' => 9900,
            'currency' => 'KRW',
            'occurred_at' => '2026-09-14T12:34:56.789Z',
        ]);

        self::assertTrue($event->isValid());
        self::assertSame('evt_01J8XM', $event->eventId);
        self::assertSame(self::UID, $event->paymentUid);
        self::assertSame('captured', $event->status);
        self::assertSame(9900, $event->amountMinor);
        self::assertSame('KRW', $event->currency);
        self::assertSame('2026-09-14T12:34:56.789Z', $event->occurredAt);
    }

    public function test_금액이_없어도_통과한다(): void
    {
        $event = WebhookEvent::fromBody(self::body());

        self::assertTrue($event->isValid());
        self::assertNull($event->amountMinor);
        self::assertNull($event->currency);
        self::assertNull($event->occurredAt);
    }

    /** @dataProvider 상태 */
    public function test_status_는_화이트리스트다(mixed $status, bool $expected): void
    {
        $event = WebhookEvent::fromBody(self::body(['status' => $status]));

        self::assertSame($expected, $event->isValid());

        if (!$expected) {
            self::assertSame('status', $event->invalidField);
        }
    }

    public static function 상태(): array
    {
        return [
            'pending' => ['pending', true],
            'authorized' => ['authorized', true],
            'captured' => ['captured', true],
            'failed' => ['failed', true],

            // 환불은 전이표에 있지만 웹훅으로는 받지 않는다. 코인 lot
            // 회수가 없는 채로 전이만 시키면 "환불됐는데 코인은 그대로"
            // 라는 상태가 남는다 → 계획 0장
            'refunded — 이번 범위 밖' => ['refunded', false],

            // created 는 전이의 목적지가 아니다. 통과시키면 늘 무시(200)로
            // 떨어져 "PG 가 이상한 걸 보냈다" 가 무시 더미에 섞인다.
            'created' => ['created', false],

            '대문자' => ['CAPTURED', false],
            '모르는 상태' => ['cancelled', false],
            '빈 값' => ['', false],
            '없음' => [null, false],
            '배열' => [['captured'], false],
        ];
    }

    /** @dataProvider 식별자 */
    public function test_payment_uid_는_32자_hex_다(mixed $raw, ?string $expected): void
    {
        $event = WebhookEvent::fromBody(self::body(['payment_uid' => $raw]));

        if ($expected === null) {
            self::assertFalse($event->isValid());
            self::assertSame('payment_uid', $event->invalidField);

            return;
        }

        self::assertTrue($event->isValid());
        self::assertSame($expected, $event->paymentUid);
    }

    public static function 식별자(): array
    {
        return [
            '정상' => [self::UID, self::UID],
            '대문자는 내려 준다' => [strtoupper(self::UID), self::UID],
            '하이픈 표기' => ['0a1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9', null],
            '길이 부족' => ['0a1b2c3d', null],
            'hex 아님' => [str_repeat('z', 32), null],
            '없음' => [null, null],
            '배열' => [[self::UID], null],
        ];
    }

    /**
     * event_id 는 판정에 쓰지 않는다. 그래도 필수다 —
     * 측정에서 8건의 웹훅을 서로 가려낼 단서가 raw_payload 의 이 값뿐이다.
     */
    public function test_event_id_는_필수다(): void
    {
        foreach ([null, '', '   ', ['evt']] as $raw) {
            $event = WebhookEvent::fromBody(self::body(['event_id' => $raw]));

            self::assertFalse($event->isValid());
            self::assertSame('event_id', $event->invalidField);
        }
    }

    /** @dataProvider 금액 */
    public function test_amount_minor_는_정수만_받는다(mixed $raw, ?int $expected): void
    {
        $event = WebhookEvent::fromBody(self::body(['amount_minor' => $raw, 'currency' => 'KRW']));

        if ($expected === null) {
            self::assertFalse($event->isValid());
            self::assertSame('amount_minor', $event->invalidField);

            return;
        }

        self::assertTrue($event->isValid());
        self::assertSame($expected, $event->amountMinor);
    }

    public static function 금액(): array
    {
        return [
            '정수' => [9900, 9900],
            '문자열 정수' => ['9900', 9900],
            '소수부 없는 float' => [9900.0, 9900],
            '소수부 있음' => [99.5, null],
            '숫자 아님' => ['9,900', null],
            '참' => [true, null],
        ];
    }

    public function test_currency_는_ISO_4217_alpha_3_다(): void
    {
        $ok = WebhookEvent::fromBody(self::body(['currency' => 'krw']));
        $bad = WebhookEvent::fromBody(self::body(['currency' => '410']));

        self::assertTrue($ok->isValid());
        self::assertSame('KRW', $ok->currency);

        self::assertFalse($bad->isValid());
        self::assertSame('currency', $bad->invalidField);
    }

    /**
     * **금액과 통화를 짝지어 강제하지 않는다.**
     *
     * ConversionInput 은 둘을 함께 받게 막는데, 여기서는 그러지 않는다 —
     * 이 값들은 기록용이고 전환에 실릴 금액은 payments 에서 읽기 때문이다
     * (계획 6장). 짝이 안 맞는다고 진짜 captured 를 422 로 돌려보내면
     * 그 결제는 영영 전환되지 않는다.
     */
    public function test_금액만_와도_통과한다(): void
    {
        self::assertTrue(WebhookEvent::fromBody(self::body(['amount_minor' => 9900]))->isValid());
        self::assertTrue(WebhookEvent::fromBody(self::body(['currency' => 'KRW']))->isValid());
    }

    /** occurred_at 은 판정에 쓰지 않으므로 형식을 보지 않는다. 기록만 한다. */
    public function test_occurred_at_은_해석하지_않고_그대로_둔다(): void
    {
        $event = WebhookEvent::fromBody(self::body(['occurred_at' => '어제쯤']));

        self::assertTrue($event->isValid());
        self::assertSame('어제쯤', $event->occurredAt);
    }

    public function test_거절하면_어느_필드인지_남긴다(): void
    {
        $event = WebhookEvent::fromBody([]);

        self::assertFalse($event->isValid());
        self::assertSame('payment_uid', $event->invalidField);
        self::assertIsString($event->reason);
        self::assertNotSame('', $event->reason);
    }
}
