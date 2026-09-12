<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * `_ga` 쿠키에서 GA4 의 `client_id` 를 뽑는다.
 *
 * Measurement Protocol 로 서버가 이벤트를 보낼 때 **웹 스트림은
 * `client_id` 가 필수**다. 그 값은 우리가 만드는 것이 아니라
 * gtag.js 가 브라우저에 심은 `_ga` 쿠키 안에 들어 있다.
 *
 *   _ga = GA1.1.1234567890.1700000000
 *          │   │ └──────── client_id ────────┘
 *          │   └ 도메인 깊이 (1 또는 2)
 *          └ 버전
 *
 * 이 값을 못 얻으면 서버 전송은 **매 건이 새 사용자**가 된다.
 * 브라우저가 보낸 세션과 이어 붙지 않아서, GA4 에서 보면
 * "구매는 있는데 그 사람이 어디서 왔는지 모르는" 상태가 된다.
 *
 * 쿠키를 서버가 읽을 수 있는 이유는 등록 도메인이 하나이기 때문이다.
 * gtag 가 `.sshwan.com` 에 걸어 두면 `api.sshwan.com` 에도 실려 온다.
 * → docs/decisions/ADR-018-single-registered-domain.md
 */
final class GaClientId
{
    /**
     * GA1.<깊이>.<난수>.<최초 방문 시각>
     *
     * 뒤의 두 마디가 client_id 다. 앞의 두 마디는 쿠키 형식 메타데이터라
     * client_id 에 포함되지 않는다 — 붙여 보내면 GA4 가 다른 사용자로 센다.
     */
    private const PATTERN = '/\AGA\d+\.\d+\.(\d{1,20}\.\d{1,20})\z/';

    private function __construct()
    {
    }

    /**
     * @param string|null $cookie `_ga` 쿠키의 값
     */
    public static function fromCookie(?string $cookie): ?string
    {
        $value = trim((string) $cookie);

        if ($value === '') {
            return null;
        }

        return preg_match(self::PATTERN, $value, $m) === 1 ? $m[1] : null;
    }

    /**
     * 쿠키가 없을 때 쓸 대체값.
     *
     * `visit_uid` 를 client_id 형식으로 흉내 낸다. 형식만 맞을 뿐
     * **브라우저 세션과는 이어지지 않는다** — 신규 사용자로 잡힌다.
     *
     * 그래도 아무것도 안 보내는 것보다는 낫다. 전환 건수 자체는 남고,
     * 대체값을 썼다는 사실을 로그로 남기면 나중에 GA4 수치와 내부 수치가
     * 어긋날 때 원인을 찾을 수 있다.
     */
    public static function fallback(string $visitUidHex, int $firstSeenUnix): string
    {
        // 앞 8자 hex 를 10진수로. GA4 는 형식을 강제하지 않지만
        // <숫자>.<숫자> 꼴을 유지해야 도구들이 덜 놀란다.
        $left = (string) hexdec(substr($visitUidHex, 0, 8));

        return $left.'.'.$firstSeenUnix;
    }
}
