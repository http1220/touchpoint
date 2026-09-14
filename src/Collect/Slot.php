<?php

declare(strict_types=1);

namespace App\Collect;

/**
 * 배너가 놓인 자리 이름. `home_top`, `lp_related` 같은 것.
 *
 * CTR 은 **자리별로** 나눠야 뜻이 있다. 같은 작품도 첫 화면 상단과 목록
 * 맨 아래의 클릭률은 다르다. 노출과 클릭이 같은 이름을 써야 짝이 맞으므로
 * 규칙을 한 곳에 둔다.
 */
final class Slot
{
    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $slot = strtolower(trim($raw));

        return preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $slot) === 1 ? $slot : null;
    }
}
