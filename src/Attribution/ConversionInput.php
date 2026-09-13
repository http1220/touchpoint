<?php

declare(strict_types=1);

namespace App\Attribution;

/**
 * `POST /conversion` 본문 하나를 검증하고 정규화한 결과.
 *
 * 검증을 컨트롤러에 두지 않는 이유는 CorsPolicy 와 같다 — 경계값은
 * 프레임워크 밖에 있어야 테스트로 못박힌다. → ADR-017
 *
 * 통과와 거절을 한 타입에 담는다. 잘못된 본문은 **정상적으로 일어나는
 * 결과**이지 예외가 아니다. 예외로 만들면 호출자가 try 를 두르게 되고
 * "어느 필드가 왜 걸렸는가" 가 메시지 문자열 안으로 사라진다.
 * CorsDecision 과 같은 모양이다.
 */
final class ConversionInput
{
    /**
     * 전환 종류 화이트리스트.
     *
     * 여기 없는 값을 통과시키면 매체 이벤트 이름으로 옮길 곳이 없어
     * 전부 `custom_conversion` 으로 뭉개진다 → Ga4Channel::eventName
     */
    public const TYPES = ['signup', 'purchase', 'subscribe'];

    private function __construct(
        public readonly ?string $visitUid,
        public readonly ?string $userUid,
        public readonly string $type,
        public readonly ?int $valueMinor,
        public readonly ?string $currency,
        public readonly string $dedupKey,
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
     * JSON 본문에서 만든다.
     *
     * 검사 순서는 **싼 것부터**가 아니라 **호출자가 먼저 고쳐야 할 것부터**다.
     * type 과 dedup_key 가 없으면 나머지 값이 맞아도 기록할 수 없다.
     *
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $type = self::text($body['type'] ?? null);

        if (!in_array($type, self::TYPES, true)) {
            return self::reject('type', 'type 은 '.implode(' | ', self::TYPES).' 중 하나여야 합니다.');
        }

        $dedupKey = self::text($body['dedup_key'] ?? null);

        if ($dedupKey === '') {
            return self::reject('dedup_key', 'dedup_key 는 필수입니다. 같은 사건이면 반드시 같은 값이어야 합니다.');
        }

        /*
         * 길이를 여기서 막는다. conversions.dedup_key 는 VARCHAR(64) 라
         * 넘치면 MySQL 이 잘라 넣거나(비엄격 모드) 오류를 낸다. 잘리면
         * 서로 다른 사건 둘이 같은 키가 되어 **두 번째 전환이 조용히
         * 중복으로 처리된다** — 매출이 사라지는 경로다.
         */
        if (strlen($dedupKey) > DedupKey::MAX_LENGTH) {
            return self::reject('dedup_key', 'dedup_key 는 '.DedupKey::MAX_LENGTH.'바이트를 넘을 수 없습니다.');
        }

        $valueMinor = null;

        if (self::present($body['value_minor'] ?? null)) {
            $valueMinor = self::integer($body['value_minor']);

            if ($valueMinor === null) {
                return self::reject('value_minor', 'value_minor 는 정수 minor unit 이어야 합니다. KRW 9,900원은 9900 이고 99.00 이 아닙니다.');
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
         * 금액과 통화는 함께 온다.
         *
         * minor unit 은 통화를 모르면 해석할 수 없다 — 9900 이 ₩9,900 인지
         * $99.00 인지 갈린다. 한쪽만 받아 두면 나중에 표시 단위로 바꾸는
         * 자리(Ga4Channel::majorUnits)에서 기본값을 추측하게 되고,
         * 그 추측은 매체 대시보드의 매출로만 드러난다.
         */
        if (($valueMinor === null) !== ($currency === null)) {
            return self::reject(
                $valueMinor === null ? 'value_minor' : 'currency',
                'value_minor 와 currency 는 함께 보내야 합니다. 금액 없는 전환이면 둘 다 비웁니다.'
            );
        }

        $visitUid = null;

        if (self::present($body['visit_uid'] ?? null)) {
            $visitUid = self::uidHex($body['visit_uid']);

            if ($visitUid === null) {
                return self::reject('visit_uid', 'visit_uid 는 32자 hex 여야 합니다.');
            }
        }

        $userUid = null;

        if (self::present($body['user_uid'] ?? null)) {
            $userUid = self::uidHex($body['user_uid']);

            if ($userUid === null) {
                return self::reject('user_uid', 'user_uid 는 32자 hex 여야 합니다.');
            }
        }

        return new self($visitUid, $userUid, $type, $valueMinor, $currency, $dedupKey, null, null);
    }

    // ────────────────────────────────────────────────────────

    private static function reject(string $field, string $reason): self
    {
        return new self(null, null, '', null, null, '', $field, $reason);
    }

    /**
     * "값이 왔는가".
     *
     * 없는 것과 비운 것을 같게 본다. 클라이언트가 빈 문자열을 채워 보내는
     * 경우가 흔한데, 그걸 "왔다" 로 세면 형식 검사에서 전부 422 가 된다.
     */
    private static function present(mixed $raw): bool
    {
        return $raw !== null && $raw !== '';
    }

    /** 배열·객체가 오면 빈 문자열이 되어 화이트리스트·필수 검사에 걸린다. */
    private static function text(mixed $raw): string
    {
        return is_string($raw) || is_int($raw) ? trim((string) $raw) : '';
    }

    /**
     * 정수 minor unit.
     *
     * JSON 은 9900 과 9900.0 을 구분하지 않고 실어 올 수 있고, 폼에서 온
     * 값은 "9900" 문자열이다. 셋 다 받되 **소수부가 있으면 거절한다** —
     * 99.5 를 (int) 로 밀어 넣으면 50원이 조용히 사라진다.
     *
     * 자릿수를 18 로 막는 이유는 문자열 → int 변환이 PHP_INT_MAX 에서
     * 조용히 포화되기 때문이다. BIGINT 범위를 넘는 금액은 오타다.
     */
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

    /** ISO 4217 alpha-3. 코드 목록까지 검사하지 않는다 — 형식만 본다. */
    private static function currency(mixed $raw): ?string
    {
        $value = strtoupper(self::text($raw));

        return preg_match('/\A[A-Z]{3}\z/', $value) === 1 ? $value : null;
    }

    /**
     * BINARY(16) 식별자의 문자열 표기.
     *
     * 소문자로 맞춘다. bin2hex() 가 소문자를 내므로 여기서 정규화해 두지
     * 않으면 대문자로 보낸 요청이 DB 조회에서만 조용히 빗나간다.
     */
    private static function uidHex(mixed $raw): ?string
    {
        $value = strtolower(self::text($raw));

        return preg_match('/\A[0-9a-f]{32}\z/', $value) === 1 ? $value : null;
    }
}
