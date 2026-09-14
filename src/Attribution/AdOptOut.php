<?php

declare(strict_types=1);

namespace App\Attribution;

/**
 * 맞춤형 광고 거부 쿠키.
 *
 * 한 곳에서 판정한다. 픽셀 뷰·서버 전환·방침 화면이 각자 쿠키 값을 해석하면
 * 한쪽만 "1" 이 아닌 "true" 를 받아들이는 식으로 어긋난다.
 * → application/controllers/Privacy.php
 */
final class AdOptOut
{
    public const COOKIE = 'tp_ad_optout';

    /** 1년. 거부가 조용히 풀리면 안 된다. */
    public const MAX_AGE = 31536000;

    public static function isOn(mixed $cookie): bool
    {
        return $cookie === '1';
    }
}
