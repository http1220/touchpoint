<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\AdAttribution;
use PHPUnit\Framework\TestCase;

final class AdAttributionTest extends TestCase
{
    /** 모델이 내려 주는 행 한 줄 — 빠뜨리기 쉬운 자리(type)를 기본값으로 채워 둔다 */
    private function row(string $position, ?string $source, int $n, int $minor = 0, string $type = 'purchase', ?string $currency = null): array
    {
        return [
            'position' => $position,
            'source' => $source,
            'type' => $type,
            'conversions' => $n,
            'value_minor' => $minor,
            'currency' => $minor > 0 ? ($currency ?? 'KRW') : $currency,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            $this->row('first', 'naver', 3),
            $this->row('last', 'naver', 1, 9900),
            $this->row('first', 'meta', 1),
            $this->row('last', 'meta', 3, 28000),
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
            $this->row('first', 'naver', 1, 9900),
            $this->row('last', 'naver', 1, 9900),
        ]);

        self::assertSame(9900, $table['value_total']);
    }

    public function test_환불은_매출로_세지_않는다(): void
    {
        /*
         * 환불 전환의 value_minor 는 **양수**다(원 결제 금액 그대로).
         * 유형을 보지 않고 더하면 9,900원을 환불한 자리가 19,800원 매출로 잡힌다.
         */
        $table = AdAttribution::table([
            $this->row('last', 'naver', 1, 9900, 'purchase'),
            $this->row('last', 'naver', 1, 9900, 'refund'),
        ]);

        self::assertSame(9900, $table['value_total']);
        self::assertSame(9900, $table['refund_total']);

        // 전환 건수는 둘 다 센다 — 환불도 매체로 보내는 전환이다
        self::assertSame(2, $table['last_total']);
        self::assertSame(9900, $table['rows'][0]['value_minor']);
        self::assertSame(9900, $table['rows'][0]['refund_minor']);
    }

    public function test_금액이_없는_유형은_어느_쪽에도_안_더한다(): void
    {
        $table = AdAttribution::table([
            $this->row('last', 'naver', 2, 0, 'signup'),
            $this->row('last', 'naver', 1, 9900, 'purchase'),
        ]);

        self::assertSame(9900, $table['value_total']);
        self::assertSame(0, $table['refund_total']);
        self::assertSame(3, $table['last_total']);
    }

    public function test_기준을_바꿔도_안_움직이면_moved_는_거짓이다(): void
    {
        $table = AdAttribution::table([
            $this->row('first', 'naver', 2),
            $this->row('last', 'naver', 2, 1000),
        ]);

        self::assertFalse($table['moved']);
        self::assertFalse($table['rows'][0]['moved']);
    }

    public function test_이름_없는_매체는_한_줄로_모은다(): void
    {
        $table = AdAttribution::table([
            $this->row('last', null, 1, 100),
            $this->row('last', '  ', 2, 200),
        ]);

        self::assertCount(1, $table['rows']);
        self::assertSame(AdAttribution::UNNAMED, $table['rows'][0]['source']);
        self::assertSame(3, $table['rows'][0]['last']);
        self::assertSame(300, $table['value_total']);
    }

    public function test_모르는_position_은_버린다(): void
    {
        $table = AdAttribution::table([
            $this->row('middle', 'naver', 9, 9900),
            $this->row('last', 'naver', 1, 1000),
        ]);

        self::assertSame(1, $table['last_total']);
        self::assertSame(0, $table['first_total']);
        self::assertSame(1000, $table['value_total']);
    }

    public function test_많이_만든_매체가_위로_온다(): void
    {
        $table = AdAttribution::table([
            $this->row('last', 'naver', 1),
            $this->row('last', 'meta', 5),
            $this->row('last', 'kakao', 3),
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
        self::assertSame(0, $table['refund_total']);
        self::assertSame([], $table['currencies']);
        self::assertFalse($table['moved']);
    }

    public function test_문자열로_온_숫자도_더한다(): void
    {
        // PDO 는 설정에 따라 숫자를 문자열로 준다
        $table = AdAttribution::table([
            ['position' => 'last', 'source' => 'naver', 'type' => 'purchase', 'conversions' => '2', 'value_minor' => '9900', 'currency' => 'KRW'],
        ]);

        self::assertSame(2, $table['last_total']);
        self::assertSame(9900, $table['value_total']);
    }

    public function test_유형이_없는_행은_금액을_더하지_않는다(): void
    {
        // 유형을 안 내려 주는 호출이 생기면 건수만 세고 금액은 비운다 — 조용히 매출을 만들지 않는다
        $table = AdAttribution::table([
            ['position' => 'last', 'source' => 'naver', 'conversions' => 1, 'value_minor' => 9900, 'currency' => 'KRW'],
        ]);

        self::assertSame(1, $table['last_total']);
        self::assertSame(0, $table['value_total']);
    }
}
