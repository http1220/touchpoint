<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 웹훅 서명. HMAC-SHA256.
 *
 *   서명 대상:  <timestamp> + "." + <원본 바디 바이트 그대로>
 *   헤더:       X-Pg-Signature: t=<unix>,v1=<hex64>
 *
 * PG 가 스텁이라도 여기는 흉내를 낸다. **인증이 없는 이 프로젝트에서
 * 서명이 유일한 실질 방어선이기 때문이다** — 전환과 코인은 `captured`
 * 웹훅에서만 발화하고, 그 웹훅을 위조할 수 없다면 아무나 GA4 를 오염시킬
 * 수 없다 → 계획 12장 결정 5
 *
 * 09-19 오후부터 **서버가 대신 서명하는 문이 하나** 있다 — 카드 없는 시연 버튼
 * (controllers/Pay.php stub_confirm). 그 문은 "방금 만든 demo- 결제" 만 받는다.
 * 서명은 여전히 위조를 막지만, 그 문을 지나는 시연 전환은 막지 않는다 → docs/plan-multi-pg.md 6장
 *
 * 규칙 넷, 전부 이유가 있다.
 *
 *   ① **원본 바이트로 서명한다.** 파싱한 배열을 재인코딩하지 않는다 —
 *      키 순서 하나, 공백 하나로 서명이 깨진다.
 *   ② `hash_equals()` 로 비교한다. `===` 는 다른 바이트에서 끊겨
 *      비교 시간이 새 나간다.
 *   ③ `t` 가 허용 창 밖이면 거절한다. 서명이 맞아도 어제 것이면
 *      재생 공격이다.
 *   ④ **서명 판정이 본문 파싱보다 먼저다.** 서명 안 된 본문은 해석하지
 *      않는다 (호출자 규약이다 — Webhook 컨트롤러가 지킨다).
 *
 * 시각은 주입받는다. `time()` 을 안에서 부르면 재생 창을 테스트할 수 없다.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Pg-Signature';

    /** 계획 5장. .env 의 PG_WEBHOOK_TOLERANCE_SEC 가 이 값을 덮는다. */
    public const DEFAULT_TOLERANCE_SEC = 300;

    private const SCHEME = 'v1';

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
    ) {
    }

    /**
     * 보낼 헤더 값을 만든다.
     *
     * 스텁 PG(`cli/pg`)와 검증이 **같은 함수**를 쓴다. 서명하는 쪽을 따로
     * 구현하면 "우리 검증기만 통과하는 서명" 을 만들어 놓고 맞다고 믿게 된다.
     */
    public function header(string $body, int $timestamp): string
    {
        return 't='.$timestamp.','.self::SCHEME.'='.$this->digest($body, $timestamp);
    }

    /**
     * @param string|null $header X-Pg-Signature 헤더 값
     * @param string      $body   **원본 바디 바이트**
     * @param int         $now    유닉스 초
     */
    public function verify(?string $header, string $body, int $now): SignatureVerdict
    {
        if ($this->secret === '') {
            /*
             * 비밀키가 비었다.
             *
             * 빈 키로 HMAC 을 계산해 비교하면 **누구나 통과한다** — 계산식이
             * 공개돼 있으니 키가 없다는 것은 자물쇠가 없다는 뜻이다.
             * 설정 누락은 전부 거절 쪽으로 넘어뜨린다: 웹훅이 401 로 쌓이는
             * 것은 복구할 수 있지만, 위조 웹훅이 지급한 코인은 되돌릴 수 없다.
             */
            return SignatureVerdict::reject('PG_WEBHOOK_SECRET 이 비어 있어 전부 거절한다.');
        }

        $parsed = self::parseHeader($header);

        if ($parsed === null) {
            return SignatureVerdict::reject('서명 헤더가 없거나 t=…,'.self::SCHEME.'=… 형식이 아니다.');
        }

        [$timestamp, $given] = $parsed;

        /*
         * 미래도 막는다.
         *
         * 과거만 막으면 t 를 한참 뒤로 적은 서명 하나로 재생 창이 사실상
         * 영구해진다. 시계 차이는 허용 창 안에서 흡수한다.
         */
        if (abs($now - $timestamp) > $this->toleranceSec) {
            return SignatureVerdict::reject(sprintf(
                't 가 허용 창 밖이다. 차이=%d초 창=±%d초',
                $now - $timestamp,
                $this->toleranceSec
            ));
        }

        if (!hash_equals($this->digest($body, $timestamp), $given)) {
            return SignatureVerdict::reject('서명이 일치하지 않는다. 본문이 바뀌었거나 비밀키가 다르다.');
        }

        return SignatureVerdict::pass();
    }

    // ────────────────────────────────────────────────────────

    private function digest(string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);
    }

    /**
     * `t=1757836800,v1=<hex>` 를 쪼갠다.
     *
     * 값이 둘 다 있어야 하고 v1 은 hex 여야 한다. 형식 검사를 여기서
     * 끝내 두면 hash_equals 가 늘 같은 길이의 문자열 둘을 비교한다.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function parseHeader(?string $header): ?array
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pos = strpos($part, '=');

            if ($pos === false) {
                continue;
            }

            $key = trim(substr($part, 0, $pos));
            $value = trim(substr($part, $pos + 1));

            if ($key === 't' && preg_match('/\A\d{1,12}\z/', $value) === 1) {
                $timestamp = (int) $value;
            }

            if ($key === self::SCHEME && preg_match('/\A[0-9a-f]{64}\z/i', $value) === 1) {
                $signature = strtolower($value);
            }
        }

        return ($timestamp === null || $signature === null) ? null : [$timestamp, $signature];
    }
}
