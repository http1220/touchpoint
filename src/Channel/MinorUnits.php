<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * 정수 minor unit → 매체가 기대하는 표시 단위.
 *
 * ISO 4217 의 소수 자릿수가 통화마다 다르다. KRW·JPY 는 0 자리라
 * 그대로지만 USD 는 100 으로 나눠야 한다.
 *
 * Ga4Channel 안에 있던 것을 **두 번째 매체(Meta)를 붙이면서 꺼냈다.**
 * 매체가 하나일 때는 어댑터 안에 있는 게 맞았고, 둘이 되자 복사하거나
 * 꺼내거나 둘 중 하나였다. 복사하면 통화 하나를 추가할 때 한쪽을 빠뜨린다.
 * → docs/decisions/ADR-005-channel-adapter.md 「검증」
 */
final class MinorUnits
{
    public static function toMajor(string $currency, int $minor): int|float
    {
        $exponent = match (strtoupper($currency)) {
            'KRW', 'JPY', 'VND', 'CLP' => 0,
            'BHD', 'KWD', 'OMR', 'TND' => 3,
            default => 2,
        };

        // PHP 의 / 는 나누어떨어지면 int 를, 아니면 float 를 돌려준다.
        //   9900 / 100 → int 99
        //   9950 / 100 → float 99.5
        //
        // 통화가 정하는 타입으로 맞춰 둔다. 다만 **JSON 에서는 이 구분이
        // 사라진다** — json_encode(99.0) 은 "99" 다(serialize_precision=-1).
        // 그래서 이 캐스팅은 나가는 본문이 아니라 PHP 안에서의 일관성을
        // 위한 것이고, 매체가 보는 값은 어느 쪽이든 같다.
        return $exponent === 0 ? $minor : (float) ($minor / (10 ** $exponent));
    }
}
