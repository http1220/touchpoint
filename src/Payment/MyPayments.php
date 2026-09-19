<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * "이 브라우저에서 만든 결제" — 결제 이력 화면(/pay/history)의 위쪽 표.
 *
 * 쿠키에 결제 uid 를 최근 것부터 MAX 개까지 담는다. 결제 행에는 누가 만들었는지가
 * 없다 — 시연 결제는 전부 같은 시연 회원이고 로그인이 없다. 그래서 만든 쪽이 기억한다.
 *
 * ── 서명하지 않는 이유 ──
 *
 * 남의 uid 를 자기 쿠키에 넣으면 그 결제가 "내 결제" 칸에 뜨고 상세 링크가 생긴다.
 * 그런데 상세(/pay/result/{uid})는 원래 입장권만 있으면 **아무 uid 나** 연다. 쿠키가
 * 여는 문이 새로 생기지 않는다. 막는 것은 uid 를 모른다는 사실이고, 이력 화면은
 * 남의 결제를 uid 앞 12자리(UUIDv7 의 시각 부분)로만 보인다.
 *
 * 형식: `<32 hex>.<32 hex>…` — 쉼표·세미콜론 없이 쿠키 값에 그대로 들어간다.
 */
final class MyPayments
{
    public const COOKIE = 'tp_pay_mine';

    /** 최대 개수. 32 × 20 + 구분자 = 659바이트 — 쿠키 한 개 한도(4KB) 안 */
    public const MAX = 20;

    /** 쿠키 수명(초). 입장권(1일)보다 길다 — 면접관이 며칠 뒤 다시 열어도 자기 결제가 보이게 */
    public const TTL = 2592000;

    /** @return list<string> 최근 것이 앞. 형식이 틀린 조각은 버린다 */
    public static function parse(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $out = [];

        foreach (explode('.', strtolower($raw)) as $uid) {
            if (preg_match('/\A[0-9a-f]{32}\z/', $uid) === 1 && !in_array($uid, $out, true)) {
                $out[] = $uid;
            }

            if (count($out) === self::MAX) {
                break;
            }
        }

        return $out;
    }

    /** 새 uid 를 맨 앞에. 이미 있으면 앞으로 옮긴다. 넘치면 가장 오래된 것부터 버린다 */
    public static function add(mixed $raw, string $uid): string
    {
        $uid  = strtolower($uid);
        $list = self::parse($raw);

        if (preg_match('/\A[0-9a-f]{32}\z/', $uid) === 1) {
            $list = array_values(array_diff($list, [$uid]));
            array_unshift($list, $uid);
        }

        return implode('.', array_slice($list, 0, self::MAX));
    }
}
