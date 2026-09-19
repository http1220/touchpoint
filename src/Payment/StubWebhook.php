<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 스텁 PG 가 보내는 웹훅 본문과, 시연 버튼이 확정해도 되는 결제의 규칙.
 *
 * 본문은 `cli/pg`(측정 장비)와 `/pay/stub/confirm`(카드 없는 시연)이 **같은 함수**로
 * 만든다. 두 곳에서 따로 만들면 한쪽만 필드가 바뀌는 날 시연은 되는데 측정은
 * 안 되는(또는 반대) 일이 생긴다 → WebhookSignature 가 서명·검증을 한 함수로 둔 것과 같은 이유
 *
 * 금액은 싣지만 **서버는 쓰지 않는다** — 금액의 진실은 우리 payments 행이다
 * → docs/plan-payment-webhook.md 6장
 */
final class StubWebhook
{
    /** 시연 버튼이 만드는 결제의 멱등 키 접두어. 측정·운영 결제와 가른다 */
    public const DEMO_KEY_PREFIX = 'demo-';

    /** 시연 결제를 확정할 수 있는 시간(초). 오래된 행을 남이 골라 확정하지 못하게 */
    public const DEMO_MAX_AGE_SEC = 600;

    public static function body(
        string $paymentUid,
        string $status,
        int $amountMinor,
        string $currency,
        string $eventId,
        string $occurredAt,
    ): string {
        return (string) json_encode([
            'event_id' => $eventId,
            'payment_uid' => $paymentUid,
            'status' => $status,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * 시연 버튼이 이 결제를 확정해도 되는가. 된다면 null, 안 되면 이유.
     *
     * 확정 엔드포인트는 공개 입장권으로 열린다. 아무 uid 나 받으면 D-3 측정용
     * 결제나 남이 만든 결제를 골라 captured 로 만들 수 있다 — 그래서 **이 버튼이
     * 방금 만든 결제만**: 스텁 · created · demo- 키 · 10분 이내.
     */
    public static function demoRejects(string $pg, string $status, string $idempotencyKey, int $createdAtUnix, int $now): ?string
    {
        if ($pg !== 'stub') {
            return 'not-stub';
        }

        if ($status !== PaymentStatus::CREATED) {
            return 'not-created';
        }

        if (!str_starts_with($idempotencyKey, self::DEMO_KEY_PREFIX)) {
            return 'not-demo';
        }

        if ($now - $createdAtUnix > self::DEMO_MAX_AGE_SEC || $createdAtUnix > $now + 60) {
            return 'too-old';
        }

        return null;
    }
}
