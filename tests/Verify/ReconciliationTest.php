<?php

declare(strict_types=1);

namespace App\Tests\Verify;

use App\Verify\Reconciliation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 대조 규칙을 못박는다.
 *
 * 이 클래스가 답하는 질문은 "전송이 성공했는가" 가 아니라
 * **"매체가 실제로 집계했는가"** 다. 둘을 섞으면 `204` 를 받고 버려진
 * 전송이 성공으로 집계된다.
 */
final class ReconciliationTest extends TestCase
{
    public function test_전부_집계되면_반영률_100(): void
    {
        $r = Reconciliation::of(['a', 'b', 'c'], ['a' => 1, 'b' => 1, 'c' => 1]);

        self::assertSame(['a', 'b', 'c'], $r->matched);
        self::assertSame([], $r->missing);
        self::assertSame(1.0, $r->reflectionRate());
        self::assertTrue($r->isClean());
    }

    public function test_보냈는데_없으면_누락이다(): void
    {
        $r = Reconciliation::of(['a', 'b'], ['a' => 1]);

        self::assertSame(['b'], $r->missing);
        self::assertSame(0.5, $r->reflectionRate());
        self::assertFalse($r->isClean());
    }

    /** 이 프로젝트가 되읽기를 넣은 이유 그 자체다. */
    public function test_204를_받아도_집계되지_않을_수_있다(): void
    {
        // 전송 로그에는 셋 다 성공(204)으로 남아 있는 상황
        $r = Reconciliation::of(['a', 'b', 'c'], ['a' => 1]);

        self::assertCount(2, $r->missing);
        self::assertEqualsWithDelta(0.333, $r->reflectionRate(), 0.001);
    }

    public function test_한_건이_두_번_세어지면_중복이다(): void
    {
        $r = Reconciliation::of(['a', 'b'], ['a' => 2, 'b' => 1]);

        // 일치이면서 동시에 중복이다. 둘은 배타적이지 않다 —
        // 집계는 됐는데 두 번 됐다는 뜻이라 반영률은 100% 다.
        self::assertSame(['a', 'b'], $r->matched);
        self::assertSame(['a' => 2], $r->duplicated);
        self::assertSame(1.0, $r->reflectionRate());
        self::assertFalse($r->isClean());
    }

    public function test_안_보냈는데_있으면_초과다(): void
    {
        $r = Reconciliation::of(['a'], ['a' => 1, 'z' => 1]);

        self::assertSame(['z'], $r->unexpected);

        // 초과는 반영률을 깎지 않는다. 우리가 보낸 것은 다 들어갔다.
        self::assertSame(1.0, $r->reflectionRate());
    }

    public function test_같은_id를_두_번_보내도_한_건으로_센다(): void
    {
        $r = Reconciliation::of(['a', 'a', 'b'], ['a' => 1, 'b' => 1]);

        self::assertSame(2, $r->sentCount);
        self::assertSame(1.0, $r->reflectionRate());
    }

    public function test_보낸_것이_없으면_0으로_떨어진다(): void
    {
        $r = Reconciliation::of([], []);

        self::assertSame(0.0, $r->reflectionRate());
        self::assertSame(0, $r->sentCount);
    }

    // ── 처리 창 — "늦음" 과 "사라짐" 을 가른다 ───────────────

    /**
     * 09-13 에 실제로 한 오판을 입력으로 넣는다.
     *
     * 543건을 보냈고 +25시간에 517건이 보였다. 그때 "26건 영구 유실" 이라고
     * 적었는데 +40시간에 543건 전부 보였다 → docs/benchmarks.md 5-1
     */
    public function test_처리_창_안에서_안_보이는_것은_누락이_아니라_대기다(): void
    {
        [$sent, $observed, $sentAt] = $this->ga4Run(sent: 543, observed: 517, sentAt: '2026-09-12 13:00:00');

        $at25h = Reconciliation::of($sent, $observed, $sentAt, new DateTimeImmutable('2026-09-13 14:00:00'), Reconciliation::GA4_WINDOW_SECONDS);

        self::assertCount(26, $at25h->pending, '+25h 는 GA4 처리 창(48h) 안이다');
        self::assertSame([], $at25h->missing, '그때 "영구 유실" 이라고 적은 것이 오판이었다');
        self::assertTrue($at25h->isClean());
        self::assertFalse($at25h->isSettled(), '반영률 95.2% 는 최종값이 아니다');
        self::assertEqualsWithDelta(0.952, $at25h->reflectionRate(), 0.001);
    }

    public function test_처리_창이_지나도_안_보이면_그때_누락이다(): void
    {
        [$sent, $observed, $sentAt] = $this->ga4Run(sent: 10, observed: 9, sentAt: '2026-09-12 13:00:00');

        $at49h = Reconciliation::of($sent, $observed, $sentAt, new DateTimeImmutable('2026-09-14 14:00:00'), Reconciliation::GA4_WINDOW_SECONDS);

        self::assertCount(1, $at49h->missing);
        self::assertSame([], $at49h->pending);
        self::assertFalse($at49h->isClean());
        self::assertTrue($at49h->isSettled());
    }

    public function test_보낸_시각이_없으면_예전처럼_전부_누락이다(): void
    {
        $r = Reconciliation::of(['a', 'b'], ['a' => 1], [], new DateTimeImmutable(), Reconciliation::GA4_WINDOW_SECONDS);

        self::assertSame(['b'], $r->missing);
        self::assertSame([], $r->pending);
    }

    public function test_창_경계는_창을_넘긴_쪽이_누락이다(): void
    {
        $sentAt = new DateTimeImmutable('2026-09-12 00:00:00');
        $window = 3600;

        $justBefore = Reconciliation::of(['a'], [], ['a' => $sentAt], $sentAt->modify('+3599 seconds'), $window);
        $exactly = Reconciliation::of(['a'], [], ['a' => $sentAt], $sentAt->modify('+3600 seconds'), $window);

        self::assertSame(['a'], $justBefore->pending);
        self::assertSame(['a'], $exactly->missing);
    }

    /** @return array{0: list<string>, 1: array<string,int>, 2: array<string,DateTimeImmutable>} */
    private function ga4Run(int $sent, int $observed, string $sentAt): array
    {
        $ids = array_map(static fn (int $i): string => sprintf('t%04d', $i), range(1, $sent));
        $at = new DateTimeImmutable($sentAt);

        return [
            $ids,
            array_fill_keys(array_slice($ids, 0, $observed), 1),
            array_fill_keys($ids, $at),
        ];
    }
}
