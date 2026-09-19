<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\StubWebhook;
use App\Payment\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class StubWebhookTest extends TestCase
{
    private const UID = '0192f0a1b2c3d4e5f60718293a4b5c6d';
    private const NOW = 1_789_800_000;

    public function test_본문은_웹훅_검증을_통과하는_모양이다(): void
    {
        $body = StubWebhook::body(self::UID, 'captured', 3300, 'KRW', 'evt_1', '2026-09-19T00:00:00.000Z');
        $event = WebhookEvent::fromBody((array) json_decode($body, true));

        self::assertTrue($event->isValid(), (string) $event->reason);
        self::assertSame(self::UID, $event->paymentUid);
        self::assertSame('captured', $event->status);
        self::assertSame('evt_1', $event->eventId);
    }

    public function test_같은_입력이면_같은_바이트다(): void
    {
        // 시연은 같은 본문을 두 번 보내 "같은 알림이 두 번 와도 한 번만" 을 보인다.
        // 매번 새로 만들면 event_id 가 달라져 재전송이 아니라 다른 이벤트 둘이 된다
        self::assertSame(
            StubWebhook::body(self::UID, 'captured', 3300, 'KRW', 'evt_1', 't'),
            StubWebhook::body(self::UID, 'captured', 3300, 'KRW', 'evt_1', 't')
        );
    }

    /** @dataProvider 시연_확정 */
    public function test_시연_버튼은_방금_만든_시연_결제만_확정한다(string $pg, string $status, string $key, int $age, ?string $expected): void
    {
        self::assertSame($expected, StubWebhook::demoRejects($pg, $status, $key, self::NOW - $age, self::NOW));
    }

    public static function 시연_확정(): array
    {
        return [
            '방금 만든 시연 결제'   => ['stub', 'created', 'demo-abc', 5, null],
            '10분 끝자락'          => ['stub', 'created', 'demo-abc', 600, null],
            '10분 넘음'            => ['stub', 'created', 'demo-abc', 601, 'too-old'],
            '미래 시각'            => ['stub', 'created', 'demo-abc', -3600, 'too-old'],
            '측정용 결제(키 다름)'  => ['stub', 'created', 'it-cas-1', 5, 'not-demo'],
            '이니시스 결제'         => ['inicis', 'created', 'demo-abc', 5, 'not-stub'],
            '이미 확정됨'          => ['stub', 'captured', 'demo-abc', 5, 'not-created'],
        ];
    }
}
