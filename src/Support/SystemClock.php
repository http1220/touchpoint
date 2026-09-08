<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 운영용. 항상 UTC 를 돌려준다.
 *
 * 저장과 연산은 UTC 로 하고 표시 직전에만 변환한다는 규칙 때문에
 * 여기서 타임존을 못 박는다. 서버 타임존 설정에 의존하지 않는다.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
