<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

/**
 * 연재 요일의 "오늘".
 *
 * 저장과 연산은 UTC 다(SystemClock). 그런데 연재 요일은 **플랫폼이 공개하는 달력**이라
 * 서비스 지역의 날짜로 판정해야 한다. UTC 로 판정하면 한국 시각 00~09시에
 * 어제 요일을 "오늘"로 보여 준다 — 홈이 그랬다(gmdate('N')).
 *
 * 언어를 바꿔도 요일 기준은 바꾸지 않는다. ja-JP 는 같은 UTC+9 이고, en-US 는
 * 시간대가 여럿이라 하나를 고를 근거가 없다. 기준은 하나로 두고 화면에 적는다.
 */
final class PublishDay
{
    /** 화면에 "요일은 KST 기준"으로 적는다. 한국은 일광 절약 시간이 없다. */
    public const TIMEZONE = 'Asia/Seoul';

    public const LABELS = [1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'];

    private function __construct()
    {
    }

    /** ISO-8601 요일. 1 = 월 … 7 = 일 — work_publish_days.day_of_week 와 같은 체계 */
    public static function today(Clock $clock): int
    {
        return (int) $clock->now()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('N');
    }
}
