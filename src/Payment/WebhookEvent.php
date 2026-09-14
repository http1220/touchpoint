<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * `POST app./webhooks/pg` 본문 하나를 검증하고 정규화한 결과.
 *
 * ConversionInput 과 같은 모양이다 — 통과와 거절을 한 타입에 담고,
 * 어느 필드가 왜 걸렸는지를 값으로 들고 나간다 → ADR-017
 *
 * **여기서 거절하는 것은 전부 "재전송해도 같다"** 는 종류다. 그래서
 * 컨트롤러가 422 를 준다. 재전송하면 달라질 수 있는 것(모르는
 * payment_uid — 우리 커밋이 늦었을 수 있다)은 여기서 판정하지 않는다.
 *
 * 금액·통화는 받아서 **기록만 한다.** 전환에 실릴 금액의 진실은
 * payments 행이지 웹훅 본문이 아니다 → 계획 6장
 */
final class WebhookEvent
{
    /**
     * 웹훅으로 받는 상태.
     *
     * 전이표(PaymentStateMachine)의 to 목록에서 **refunded 를 뺀 것**이다.
     * 환불은 전이만으로 끝나지 않는다 — 코인 lot 회수(ADR-006)가 붙어야
     * 성립하고, 그게 없는 채로 전이만 시키면 "환불됐는데 코인은 그대로"
     * 라는 상태가 DB 에 남는다. 미구현을 422 로 말하는 편이 낫다 → 계획 0장
     *
     * created 도 뺀다. created 는 /purchase 의 INSERT 로만 생기고,
     * 전이표에 목적지로 없어서 통과시켜 봐야 늘 무시(200)가 된다.
     * 그러면 "PG 가 이상한 걸 보냈다" 가 무시 7건 사이에 섞여 안 보인다.
     */
    public const ACCEPTED_STATUSES = [
        PaymentStatus::PENDING,
        PaymentStatus::AUTHORIZED,
        PaymentStatus::CAPTURED,
        PaymentStatus::FAILED,
    ];

    private function __construct(
        public readonly string $eventId,
        /** 32자 소문자 hex */
        public readonly string $paymentUid,
        public readonly string $status,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        /**
         * PG 가 적어 보낸 발생 시각. **판정에 쓰지 않는다.**
         *
         * 우리 시계와 PG 시계가 다르고, 무엇보다 **재전송은 같은
         * 타임스탬프를 갖는다** — 중복과 역전을 같은 기준으로 못 가른다.
         * 순서 판정은 상태 서열이 한다 → 계획 4장. 이 값은 raw_payload 에
         * 원문으로 남는 기록이다.
         */
        public readonly ?string $occurredAt,
        /** 거절된 필드 이름. 통과했으면 null */
        public readonly ?string $invalidField,
        /** 사람이 읽을 거절 사유. Problem Details 의 detail 로 나간다 */
        public readonly ?string $reason,
    ) {
    }

    public function isValid(): bool
    {
        return $this->invalidField === null;
    }

    /**
     * 검사 순서는 **PG 가 먼저 고쳐야 할 것부터**다.
     * payment_uid 와 status 가 없으면 나머지가 맞아도 적용할 곳이 없다.
     *
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $paymentUid = self::uidHex($body['payment_uid'] ?? null);

        if ($paymentUid === null) {
            return self::reject('payment_uid', 'payment_uid 는 32자 hex 여야 합니다.');
        }

        $status = self::text($body['status'] ?? null);

        if (!in_array($status, self::ACCEPTED_STATUSES, true)) {
            return self::reject('status', 'status 는 '.implode(' | ', self::ACCEPTED_STATUSES).' 중 하나여야 합니다.');
        }

        /*
         * event_id 를 필수로 둔다.
         *
         * 판정에는 쓰지 않는다(중복은 CAS 가 막는다 → 계획 3장). 그런데
         * 측정에서 8건의 웹훅을 서로 가려내야 할 때 raw_payload 안의 이
         * 값이 유일한 단서다. 없는 채로 받아 두면 나중에 "이 이벤트가 그
         * 이벤트인가" 를 답할 수 없다.
         */
        $eventId = self::text($body['event_id'] ?? null);

        if ($eventId === '') {
            return self::reject('event_id', 'event_id 는 필수입니다.');
        }

        $amountMinor = null;

        if (self::present($body['amount_minor'] ?? null)) {
            $amountMinor = self::integer($body['amount_minor']);

            if ($amountMinor === null) {
                return self::reject('amount_minor', 'amount_minor 는 정수 minor unit 이어야 합니다.');
            }
        }

        $currency = null;

        if (self::present($body['currency'] ?? null)) {
            $currency = self::currency($body['currency']);

            if ($currency === null) {
                return self::reject('currency', 'currency 는 ISO 4217 alpha-3 코드여야 합니다.');
            }
        }

        /*
         * occurred_at 은 형식을 검사하지 않는다.
         *
         * 판정에 쓰지 않는 값 때문에 진짜 `captured` 하나를 422 로 돌려보내면
         * 그 결제는 영영 전환되지 않는다. 해석할 수 없는 문자열이면 기록만
         * 남기고 넘어가는 쪽이 손해가 작다.
         */
        $occurredAt = self::present($body['occurred_at'] ?? null) ? self::text($body['occurred_at']) : null;

        return new self($eventId, $paymentUid, $status, $amountMinor, $currency, $occurredAt === '' ? null : $occurredAt, null, null);
    }

    // ────────────────────────────────────────────────────────

    private static function reject(string $field, string $reason): self
    {
        return new self('', '', '', null, null, null, $field, $reason);
    }

    private static function present(mixed $raw): bool
    {
        return $raw !== null && $raw !== '';
    }

    private static function text(mixed $raw): string
    {
        return is_string($raw) || is_int($raw) ? trim((string) $raw) : '';
    }

    private static function integer(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_float($raw)) {
            return ($raw === floor($raw) && abs($raw) < 9.0e18) ? (int) $raw : null;
        }

        if (is_string($raw) && preg_match('/\A-?\d{1,18}\z/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }

    private static function currency(mixed $raw): ?string
    {
        $value = strtoupper(self::text($raw));

        return preg_match('/\A[A-Z]{3}\z/', $value) === 1 ? $value : null;
    }

    /** bin2hex() 가 소문자를 낸다. 대문자로 온 uid 가 조회에서만 빗나가지 않게 여기서 맞춘다. */
    private static function uidHex(mixed $raw): ?string
    {
        $value = strtolower(self::text($raw));

        return preg_match('/\A[0-9a-f]{32}\z/', $value) === 1 ? $value : null;
    }
}
