<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 테스트용. 시각을 고정하거나 원하는 만큼 흘려보낸다.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $at;

    public function __construct(string $iso8601 = '2026-09-08T00:00:00+00:00')
    {
        $this->at = new DateTimeImmutable($iso8601, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }

    /** 지정한 간격만큼 앞으로 흘려보낸다. 예: 'PT30S', 'P1Y' */
    public function advance(string $interval): void
    {
        $this->at = $this->at->add(new \DateInterval($interval));
    }
}
