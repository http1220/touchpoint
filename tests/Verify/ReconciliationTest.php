<?php

declare(strict_types=1);

namespace App\Tests\Verify;

use App\Verify\Reconciliation;
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
}
