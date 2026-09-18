<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\AdAttribution;
use PHPUnit\Framework\TestCase;

final class AdAttributionTest extends TestCase
{
    /** @return list<array{position: string, source: ?string, conversions: int, value_minor: int, currency: ?string}> */
    private function rows(): array
    {
        return [
            ['position' => 'first', 'source' => 'naver', 'conversions' => 3, 'value_minor' => 0, 'currency' => null],
            ['position' => 'last', 'source' => 'naver', 'conversions' => 1, 'value_minor' => 9900, 'currency' => 'KRW'],
            ['position' => 'first', 'source' => 'meta', 'conversions' => 1, 'value_minor' => 0, 'currency' => null],
            ['position' => 'last', 'source' => 'meta', 'conversions' => 3, 'value_minor' => 28000, 'currency' => 'KRW'],
        ];
    }

    public function test_같은_전환도_기준에_따라_다른_매체에_붙는다(): void
    {
        $table = AdAttribution::table($this->rows());

        $byName = [];
        foreach ($table['rows'] as $row) {
            $byName[$row['source']] = $row;
        }

        self::assertSame(3, $byName['naver']['first']);
        self::assertSame(1, $byName['naver']['last']);
        self::assertSame(1, $byName['meta']['first']);
        self::assertSame(3, $byName['meta']['last']);

        // 합계는 같은데 매체별로는 옮겨 갔다 — 이 표가 보여 주려는 것이 이것이다
        self::assertSame(4, $table['first_total']);
        self::assertSame(4, $table['last_total']);
        self::assertTrue($table['moved']);
        self::assertTrue($byName['naver']['moved']);
    }

    public function test_금액은_마지막_유입_기준으로만_한_번_더한다(): void
    {
        $table = AdAttribution::table($this->rows());

        // first 행에 금액이 실려 와도 무시한다. 두 번 더하면 매출이 두 배가 된다
        self::assertSame(9900 + 28000, $table['value_total']);
        self::assertSame(['KRW'], $table['currencies']);
    }

    public function test_first_행의_금액은_세지_않는다(): void
    {
        $table = AdAttribution::table([
            ['position' => 'first', 'source' => 'naver', 'conversions' => 1, 'value_minor' => 9900, 'currency' => 'KRW'],
            ['position' => 'last', 'source' => 'naver', 'conversions' => 1, 'value_minor' => 9900, 'currency' => 'KRW'],
        ]);

        self::assertSame(9900, $table['value_total']);
    }

    public function test_기준을_바꿔도_안_움직이면_moved_는_거짓이다(): void
    {
        $table = AdAttribution::table([
            ['position' => 'first', 'source' => 'naver', 'conversions' => 2, 'value_minor' => 0, 'currency' => null],
            ['position' => 'last', 'source' => 'naver', 'conversions' => 2, 'value_minor' => 1000, 'currency' => 'KRW'],
        ]);

        self::assertFalse($table['moved']);
        self::assertFalse($table['rows'][0]['moved']);
    }

    public function test_이름_없는_매체는_한_줄로_모은다(): void
    {
        $table = AdAttribution::table([
            ['position' => 'last', 'source' => null, 'conversions' => 1, 'value_minor' => 100, 'currency' => 'KRW'],
            ['position' => 'last', 'source' => '  ', 'conversions' => 2, 'value_minor' => 200, 'currency' => 'KRW'],
        ]);

        self::assertCount(1, $table['rows']);
        self::assertSame(AdAttribution::UNNAMED, $table['rows'][0]['source']);
        self::assertSame(3, $table['rows'][0]['last']);
        self::assertSame(300, $table['value_total']);
    }

    public function test_모르는_position_은_버린다(): void
    {
        $table = AdAttribution::table([
            ['position' => 'middle', 'source' => 'naver', 'conversions' => 9, 'value_minor' => 9900, 'currency' => 'KRW'],
            ['position' => 'last', 'source' => 'naver', 'conversions' => 1, 'value_minor' => 1000, 'currency' => 'KRW'],
        ]);

        self::assertSame(1, $table['last_total']);
        self::assertSame(0, $table['first_total']);
        self::assertSame(1000, $table['value_total']);
    }

    public function test_많이_만든_매체가_위로_온다(): void
    {
        $table = AdAttribution::table([
            ['position' => 'last', 'source' => 'naver', 'conversions' => 1, 'value_minor' => 0, 'currency' => 'KRW'],
            ['position' => 'last', 'source' => 'meta', 'conversions' => 5, 'value_minor' => 0, 'currency' => 'KRW'],
            ['position' => 'last', 'source' => 'kakao', 'conversions' => 3, 'value_minor' => 0, 'currency' => 'KRW'],
        ]);

        self::assertSame(['meta', 'kakao', 'naver'], array_column($table['rows'], 'source'));
    }

    public function test_행이_없으면_빈_표다(): void
    {
        $table = AdAttribution::table([]);

        self::assertSame([], $table['rows']);
        self::assertSame(0, $table['first_total']);
        self::assertSame(0, $table['last_total']);
        self::assertSame(0, $table['value_total']);
        self::assertSame([], $table['currencies']);
        self::assertFalse($table['moved']);
    }

    public function test_문자열로_온_숫자도_더한다(): void
    {
        // PDO 는 설정에 따라 숫자를 문자열로 준다
        $table = AdAttribution::table([
            ['position' => 'last', 'source' => 'naver', 'conversions' => '2', 'value_minor' => '9900', 'currency' => 'KRW'],
        ]);

        self::assertSame(2, $table['last_total']);
        self::assertSame(9900, $table['value_total']);
    }
}
