<?php

declare(strict_types=1);

namespace App\Payment;

use InvalidArgumentException;

/**
 * 실PG 경로의 입장권. → docs/plan-multi-pg.md C4 · 6장
 *
 * ── 처음엔 "토큰을 아는 사람만" 이었다 ──
 *
 * `/purchase` 는 인증이 없다. 이니시스 테스트 MID 는 **실승인**이라(자정 전
 * 자동 취소) 공개 페이지에서 누구나 결제를 일으키지 못하게 공유 토큰 하나로
 * 잠갔다. 면접관에게는 토큰이 붙은 링크를 따로 줘야 했다.
 *
 * **09-19 오후에 뒤집었다(사용자 결정).** 안내 화면(/tour)의 버튼 한 번으로
 * 누구나 입장권을 받는다 — 면접관이 별도 링크 없이 결제까지 해 볼 수 있게.
 * 잠금이 아니라 **명시적인 한 번의 동작**이 남는다:
 *
 *   - 버튼은 POST 다. 링크를 따라가는 크롤러·미리보기는 입장권을 받지 않는다
 *   - 버튼 옆에 "카드 승인이 실제로 일어난다" 를 적는다. 누르는 것이 곧 그걸 읽었다는 뜻이다
 *
 * ── 입장권은 방문자마다 다르다 ──
 *
 *   v1.<만료 유닉스초>.<임의 16바이트 hex>.<HMAC-SHA256>
 *
 * 비밀(`PAY_DEMO_TOKEN`)로 서명한다. DB 가 필요 없고, 위조할 수 없고, 만료가
 * 들어 있다. 공유 토큰의 해시를 모두에게 똑같이 주던 것보다 나은 점: 만료가
 * 있고, 입장권 하나가 새어도 비밀은 새지 않는다.
 *
 * 비밀이 비어 있으면 **아무도 못 연다.** 빈 키로 서명하면 계산식이 공개돼
 * 있으니 누구나 입장권을 만들 수 있다.
 */
final class PayAccess
{
    public const COOKIE = 'tp_pay';

    /** 입장권 수명(초). 쿠키 만료와 같다 */
    public const TTL = 86400;

    private const VERSION = 'v1';

    /** @param string|null $nonce 테스트용. 운영에서는 비워 둔다 */
    public static function issue(string $secret, int $now, ?string $nonce = null): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('PAY_DEMO_TOKEN 이 비어 있으면 입장권을 만들지 않는다.');
        }

        $body = self::VERSION.'.'.($now + self::TTL).'.'.($nonce ?? bin2hex(random_bytes(16)));

        return $body.'.'.hash_hmac('sha256', $body, $secret);
    }

    public static function allows(mixed $ticket, string $secret, int $now): bool
    {
        if ($secret === '' || !is_string($ticket)) {
            return false;
        }

        $parts = explode('.', $ticket);

        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return false;
        }

        [$version, $expires, $nonce, $sig] = $parts;

        if (!ctype_digit($expires) || (int) $expires <= $now) {
            return false;
        }

        if (preg_match('/\A[0-9a-f]{32}\z/', $nonce) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $version.'.'.$expires.'.'.$nonce, $secret), $sig);
    }

    /**
     * 운영자용 지름길 — `/pay?t=<비밀>`. 버튼 없이 바로 입장권을 받는다.
     * 공개 버튼이 생긴 뒤에도 남겨 둔다: 안내 화면이 아닌 곳(cli 확인·스크립트)에서 들어갈 때 쓴다.
     */
    public static function tokenMatches(mixed $given, string $token): bool
    {
        return $token !== '' && is_string($given) && hash_equals($token, $given);
    }
}
