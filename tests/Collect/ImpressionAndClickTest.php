<?php

declare(strict_types=1);

namespace App\Tests\Collect;

use App\Collect\ClickRequest;
use App\Collect\ImpressionBatch;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ImpressionAndClickTest extends TestCase
{
    // ── 노출 배치 ───────────────────────────────────────────

    public function test_틀린_항목만_버리고_나머지는_받는다(): void
    {
        $b = ImpressionBatch::fromBody(['items' => [
            ['work_id' => 1, 'slot' => 'lp_related'],
            ['work_id' => 'abc', 'slot' => 'lp_related'],
            ['work_id' => 2, 'slot' => 'Bad Slot!'],
            'not-an-object',
            ['work_id' => '3', 'slot' => 'LP_RELATED'],
        ]]);

        self::assertTrue($b->isValid());
        self::assertSame([['work_id' => 1, 'slot' => 'lp_related'], ['work_id' => 3, 'slot' => 'lp_related']], $b->items);
        self::assertSame(3, $b->dropped);
    }

    public function test_같은_작품_같은_자리는_한_번(): void
    {
        $b = ImpressionBatch::fromBody(['items' => [
            ['work_id' => 1, 'slot' => 'a'], ['work_id' => 1, 'slot' => 'a'], ['work_id' => 1, 'slot' => 'b'],
        ]]);

        self::assertCount(2, $b->items);
        self::assertSame(1, $b->dropped);
    }

    public function test_최대_개수를_넘으면_앞에서부터만_받는다(): void
    {
        $items = [];
        for ($i = 1; $i <= 60; $i++) {
            $items[] = ['work_id' => $i, 'slot' => 's'];
        }

        $b = ImpressionBatch::fromBody(['items' => $items]);

        self::assertCount(ImpressionBatch::MAX_ITEMS, $b->items);
        self::assertSame(10, $b->dropped);
    }

    public function test_items_가_없거나_배열이_아니면_invalid(): void
    {
        foreach ([[], ['items' => []], ['items' => 'x'], ['items' => ['a' => 1]]] as $body) {
            self::assertFalse(ImpressionBatch::fromBody($body)->isValid());
        }
    }

    // ── 클릭 ────────────────────────────────────────────────

    private const UA = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130';

    private function click(array $q, string $ua = self::UA): ClickRequest
    {
        return ClickRequest::fromQuery($q, $ua, 'example.com', new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('UTC')));
    }

    /** @dataProvider 목적지 */
    public function test_목적지는_우리_도메인_https_만_쿼리는_살린다(string $u, ?string $expected): void
    {
        self::assertSame($expected, $this->click(['u' => $u])->destination);
    }

    public static function 목적지(): array
    {
        return [
            '랜딩 + 쿼리'        => ['https://lp.example.com/l/3?vid=abc&utm_source=home', 'https://lp.example.com/l/3?vid=abc&utm_source=home'],
            '루트'              => ['https://example.com/', 'https://example.com/'],
            '조각은 버린다'       => ['https://lp.example.com/l/3#x', 'https://lp.example.com/l/3'],
            '다른 도메인'         => ['https://evil.com/', null],
            '접미사 속임'         => ['https://example.com.evil.com/', null],
            '비슷한 이름'         => ['https://notexample.com/', null],
            'http'             => ['http://lp.example.com/', null],
            '프로토콜 상대'       => ['//evil.com/', null],
            '자바스크립트'        => ['javascript:alert(1)', null],
            '사용자 정보'         => ['https://example.com@evil.com/', null],
            '백슬래시 우회'       => ['https://lp.example.com\\@evil.com/', null],
            '포트'              => ['https://lp.example.com:8443/', null],
        ];
    }

    public function test_봇과_빈_UA_는_기록하지_않는다(): void
    {
        $q = ['u' => 'https://lp.example.com/l/1', 'w' => '1', 's' => 'lp_related'];

        self::assertTrue($this->click($q)->shouldRecord());
        self::assertFalse($this->click($q, 'Googlebot/2.1')->shouldRecord());
        self::assertFalse($this->click($q, 'facebookexternalhit/1.1')->shouldRecord());
        self::assertFalse($this->click($q, '')->shouldRecord());
    }

    public function test_목적지가_틀리면_기록도_하지_않는다(): void
    {
        self::assertFalse($this->click(['u' => 'https://evil.com/', 'w' => '1', 's' => 'x'])->shouldRecord());
    }

    /** @dataProvider 기준일 */
    public function test_sd_는_노출일이고_범위_밖이면_오늘(string $sd, string $expected): void
    {
        self::assertSame($expected, $this->click(['sd' => $sd])->statDate);
    }

    public static function 기준일(): array
    {
        return [
            '어제 노출'      => ['20260914', '2026-09-14'],
            '오늘'          => ['20260915', '2026-09-15'],
            '7일 전까지'     => ['20260908', '2026-09-08'],
            '8일 전 → 오늘'  => ['20260907', '2026-09-15'],
            '내일(시계 차)'   => ['20260916', '2026-09-16'],
            '모레 → 오늘'    => ['20260917', '2026-09-15'],
            '없는 날짜'      => ['20260231', '2026-09-15'],
            '형식 틀림'      => ['2026-09-14', '2026-09-15'],
        ];
    }

    public function test_같은_방문_같은_날_같은_자리_같은_작품이면_같은_키(): void
    {
        $a = $this->click(['w' => '1', 's' => 'a', 'sd' => '20260915']);
        $b = $this->click(['w' => '1', 's' => 'a', 'sd' => '20260915']);
        $c = $this->click(['w' => '1', 's' => 'a', 'sd' => '20260914']);

        self::assertSame($a->dedupKey(7), $b->dedupKey(7));
        self::assertNotSame($a->dedupKey(7), $a->dedupKey(8), '다른 방문');
        self::assertNotSame($a->dedupKey(7), $c->dedupKey(7), '다른 노출일');
    }
}
