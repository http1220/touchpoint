<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 수집 엔드포인트의 CORS 정책.
 *
 * `lp.sshwan.com` → `api.sshwan.com` 은 **사이트는 같고 오리진은 다르다.**
 * 쿠키는 `Lax` 로 전송되지만 CORS 는 오리진 기준이라 그대로 걸린다.
 * → docs/decisions/ADR-018-single-registered-domain.md
 *
 * 이 클래스가 지키는 규칙 셋
 *
 *   ① 오리진을 **정확히 반향**한다. `*` 를 쓰지 않는다.
 *      쿠키를 실어 보내려면(`credentials: 'include'`) 브라우저가
 *      `Allow-Origin: *` 를 거부하기 때문이다.
 *
 *   ② 반향하면 **`Vary: Origin` 이 필수**다.
 *      빠뜨리면 중간 캐시가 A 오리진에 준 응답을 B 오리진에게 준다.
 *      허용 목록이 있어도 캐시가 그걸 무효로 만든다.
 *
 *   ③ 모르는 오리진에는 **아무 CORS 헤더도 붙이지 않는다.**
 *      거부는 헤더를 '붙이지 않음' 으로 표현된다 — 브라우저가 막는다.
 *      서버도 같이 거부해 기록을 남긴다.
 */
final class CorsPolicy
{
    /** preflight 결과를 브라우저가 캐시하는 시간(초). */
    public const MAX_AGE = 600;

    private const ALLOWED_METHODS = 'POST, OPTIONS';

    /**
     * 허용하는 요청 헤더.
     *
     * 요청이 보내온 목록을 그대로 반향하지 않는다. 반향하면 임의 헤더가
     * 통과해 정책이 사실상 없는 것과 같아진다.
     */
    private const ALLOWED_HEADERS = 'Content-Type';

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private readonly array $allowedOrigins,
        private readonly bool $wildcardExperiment = false,
    ) {
    }

    /**
     * 한 등록 도메인 안의 호스트들을 허용 목록으로 만든다.
     *
     * 수집기(`api.`)는 스스로를 허용 목록에 넣지 않는다. 같은 오리진에서 온
     * 요청에는 CORS 가 걸리지 않으므로 넣을 이유가 없다.
     */
    public static function forSite(string $shopDomain, bool $wildcardExperiment = false): self
    {
        $shopDomain = strtolower(trim($shopDomain));

        if ($shopDomain === '') {
            return new self([], $wildcardExperiment);
        }

        return new self([
            'https://'.$shopDomain,
            'https://lp.'.$shopDomain,
            'https://m.'.$shopDomain,
            'https://app.'.$shopDomain,
        ], $wildcardExperiment);
    }

    /**
     * OPTIONS preflight.
     *
     * 본 요청이 오기 전에 브라우저가 먼저 묻는다.
     * `Content-Type: application/json` 은 단순 요청이 아니라서 반드시 뜬다.
     */
    public function preflight(?string $origin, ?string $requestMethod): CorsDecision
    {
        $origin = self::normalize($origin);

        if ($origin === null) {
            // Origin 없는 OPTIONS 는 CORS preflight 가 아니다.
            return CorsDecision::deny(405, [], 'preflight 가 아닌 OPTIONS');
        }

        if (!$this->isAllowed($origin)) {
            return CorsDecision::deny(403, ['Vary' => 'Origin'], '허용되지 않은 오리진');
        }

        $method = strtoupper(trim((string) $requestMethod));

        if ($method !== '' && !in_array($method, ['POST', 'OPTIONS'], true)) {
            return CorsDecision::deny(403, ['Vary' => 'Origin'], '허용되지 않은 메서드: '.$method);
        }

        return CorsDecision::allow(204, $this->headers($origin) + [
            'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
            'Access-Control-Max-Age' => (string) self::MAX_AGE,
        ], 'preflight 허용');
    }

    /**
     * 본 요청.
     *
     * Origin 이 없으면 브라우저의 크로스오리진 요청이 아니다 —
     * 서버 간 호출이나 curl 이다. CORS 헤더 없이 통과시킨다.
     * 그것까지 막는 것은 CORS 의 역할이 아니다.
     */
    public function actual(?string $origin): CorsDecision
    {
        $origin = self::normalize($origin);

        if ($origin === null) {
            return CorsDecision::allow(200, [], 'Origin 없음 — 크로스오리진이 아님');
        }

        if (!$this->isAllowed($origin)) {
            return CorsDecision::deny(403, ['Vary' => 'Origin'], '허용되지 않은 오리진');
        }

        return CorsDecision::allow(200, $this->headers($origin), '허용된 오리진');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $origin): array
    {
        /*
         * 실험 스위치.
         *
         * .env 의 CORS_ALLOW_ORIGIN_WILDCARD=true 로 켜면 일부러 깨진 조합을
         * 내보낸다 — `Allow-Origin: *` 와 `Allow-Credentials: true` 는
         * 같이 쓸 수 없고, 브라우저가 요청을 거부한다.
         *
         * 문서로 읽은 것과 콘솔에 찍히는 오류 원문은 다르다.
         * 그 원문을 남기는 것이 이 스위치의 목적이다.
         * → docs/failure-scenarios.md B-2
         */
        if ($this->wildcardExperiment) {
            return [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Credentials' => 'true',
                'Vary' => 'Origin',
            ];
        }

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Vary' => 'Origin',
        ];
    }

    private function isAllowed(string $origin): bool
    {
        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * 오리진 정규화.
     *
     * 스킴과 호스트는 대소문자를 가리지 않지만 비교는 정확해야 한다.
     * 끝의 슬래시를 붙여 보내는 클라이언트가 있어 함께 떼어낸다.
     * `null` 문자열은 샌드박스 iframe 등이 보내는 값이고 허용하지 않는다.
     */
    private static function normalize(?string $raw): ?string
    {
        $value = strtolower(trim((string) $raw));
        $value = rtrim($value, '/');

        if ($value === '' || $value === 'null') {
            return null;
        }

        return $value;
    }
}
