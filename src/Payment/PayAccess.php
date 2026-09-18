<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 실PG 경로의 문. 시연 토큰을 아는 사람만 연다 → docs/plan-multi-pg.md C4 · 6장
 *
 * ── 왜 필요한가 ──
 *
 * `/purchase` 는 인증이 없다(계획 12장 결정 5). 스텁일 때는 "청구가 없다" 가
 * 그걸 감수할 근거였는데, 이니시스 테스트 MID 는 **실승인**이다(자정 전
 * 자동 취소). 공개 페이지에서 누구나 자기 카드로 실승인을 일으킬 수 있게
 * 두면 안 된다.
 *
 * **진짜 인증이 아니다.** 토큰 하나를 아는 사람은 모두 같은 권한이다.
 * 면접관에게 링크를 줄 때만 토큰을 붙인다.
 *
 * ── 쿠키에는 토큰의 해시를 담는다 ──
 *
 * `/pay?t=<토큰>` 으로 들어오면 쿠키를 걸고 토큰 없는 주소로 보낸다. 주소에
 * 토큰이 남으면 방문 기록·Referer 로 샌다. 쿠키 값이 곧 권한이라는 점은
 * 같지만, 적어도 토큰 원문이 쿠키 저장소에 남지는 않는다.
 *
 * SameSite=Lax 라서 다른 사이트의 POST 에는 실리지 않는다 — 이 쿠키를 요구하는
 * `/pay/*` POST 를 CSRF 제외 목록에 넣어도 되는 이유다. 단 **이니시스 복귀
 * (`/pay/inicis/return`)는 이 쿠키가 안 실린다**(교차 사이트 POST). 그 경로는
 * 쿠키가 아니라 이니시스 흐름(authToken · 호스트 검사)이 지킨다.
 *
 * 비밀이 비어 있으면 **아무도 못 연다.** 빈 토큰을 비교하면 빈 쿠키가 통과한다.
 */
final class PayAccess
{
    public const COOKIE = 'tp_pay';

    public static function cookieValue(string $token): string
    {
        return hash('sha256', 'tp_pay|'.$token);
    }

    public static function tokenMatches(mixed $given, string $token): bool
    {
        return $token !== '' && is_string($given) && hash_equals($token, $given);
    }

    public static function allows(mixed $cookie, string $token): bool
    {
        return $token !== '' && is_string($cookie) && hash_equals(self::cookieValue($token), $cookie);
    }
}
