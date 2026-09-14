<?php

declare(strict_types=1);

namespace App\Attribution;

/**
 * 광고 매체 전송에 쓸 브라우저 맥락 — 받아도 되는 값만 거른다.
 *
 * `/purchase` 와 `/conversion` 두 곳이 같은 규칙을 써야 해서 꺼냈다.
 * 규칙이 컨트롤러에 흩어지면 한쪽만 쿼리 제거를 빠뜨린다.
 *
 * 전부 **틀리면 NULL** 이다. 매체는 틀린 값을 거절하지 않고 조용히
 * 매칭에서 뺀다(ADR-005 실측) — 틀린 값을 보내느니 안 보낸다.
 *
 * 키 이름은 Meta 규격 그대로다. payload 에 합쳐지고 어댑터가 같은
 * 이름으로 읽는다 → src/Channel/MetaChannel.php
 */
final class ClientContext
{
    public const UA_MAX = 512;
    public const URL_MAX = 1024;

    /**
     * @return array<string, string> 값이 있는 키만
     */
    public static function from(
        ?string $userAgent,
        ?string $ip,
        ?string $pageUrl,
        ?string $fbp,
        ?string $fbc,
        string $shopDomain,
    ): array {
        $out = [
            'client_user_agent' => self::userAgent($userAgent),
            'client_ip_address' => self::ip($ip),
            'event_source_url' => self::sourceUrl($pageUrl, $shopDomain),
            'fbp' => self::fbp($fbp),
            'fbc' => self::fbc($fbc),
        ];

        return array_filter($out, static fn (?string $v): bool => $v !== null);
    }

    public static function userAgent(?string $ua): ?string
    {
        $ua = trim((string) $ua);

        return $ua === '' ? null : mb_substr($ua, 0, self::UA_MAX);
    }

    /** CI3 는 형식이 틀리면 0.0.0.0 을 준다. 그건 값이 아니다. */
    public static function ip(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '' || $ip === '0.0.0.0' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * 이벤트가 일어난 페이지.
     *
     * **우리 도메인(루트·하위)의 https 만 받고, 쿼리와 조각은 버린다.**
     * 결제·가입 페이지 쿼리에 이메일·토큰이 실리는 일이 흔하고, 그걸
     * 광고 매체로 넘기면 안 된다. 경로에 남은 것까지는 막지 못한다.
     */
    public static function sourceUrl(?string $url, string $shopDomain): ?string
    {
        $url = trim((string) $url);
        $shop = strtolower(trim($shopDomain));

        if ($url === '' || $shop === '') {
            return null;
        }

        $u = parse_url($url);

        if (!is_array($u) || strtolower((string) ($u['scheme'] ?? '')) !== 'https' || isset($u['user']) || isset($u['port'])) {
            return null;
        }

        $host = strtolower((string) ($u['host'] ?? ''));

        if ($host !== $shop && !str_ends_with($host, '.'.$shop)) {
            return null;
        }

        return mb_substr('https://'.$host.($u['path'] ?? '/'), 0, self::URL_MAX);
    }

    /** `_fbp` — fb.{하위도메인 수}.{생성 ms}.{난수} */
    public static function fbp(?string $v): ?string
    {
        $v = (string) $v;

        return preg_match('/\Afb\.\d\.\d{10,13}\.\d{1,20}\z/', $v) === 1 ? $v : null;
    }

    /** `_fbc` — fb.{하위도메인 수}.{생성 ms}.{fbclid} */
    public static function fbc(?string $v): ?string
    {
        $v = (string) $v;

        return strlen($v) <= 255 && preg_match('/\Afb\.\d\.\d{10,13}\.[A-Za-z0-9_-]+\z/', $v) === 1 ? $v : null;
    }
}
