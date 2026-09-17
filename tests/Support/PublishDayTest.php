<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\FrozenClock;
use App\Support\PublishDay;
use PHPUnit\Framework\TestCase;

final class PublishDayTest extends TestCase
{
    /** @dataProvider 시각 */
    public function test_오늘은_KST_요일이다(string $utc, int $expected): void
    {
        self::assertSame($expected, PublishDay::today(new FrozenClock($utc)));
    }

    public static function 시각(): array
    {
        return [
            // 한국 자정을 넘는 순간. UTC 로는 아직 전날이다 — 예전 홈이 여기서 틀렸다
            'KST 목 23:59:59'           => ['2026-09-17T14:59:59+00:00', 4],
            'KST 금 00:00:00'           => ['2026-09-17T15:00:00+00:00', 5],

            // 주의 경계. UTC 일요일 밤이 한국에서는 월요일이다
            'UTC 일 15:30 = KST 월'      => ['2026-09-20T15:30:00+00:00', 1],
            'UTC 일 14:00 = KST 일 23시'  => ['2026-09-20T14:00:00+00:00', 7],

            // 한국 오후 — UTC 와 KST 가 같은 날
            'KST 수 18:00'              => ['2026-09-16T09:00:00+00:00', 3],
        ];
    }

    public function test_요일_이름은_ISO_번호와_짝이다(): void
    {
        self::assertSame(['월', '화', '수', '목', '금', '토', '일'], array_values(PublishDay::LABELS));
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_keys(PublishDay::LABELS));
    }
}
