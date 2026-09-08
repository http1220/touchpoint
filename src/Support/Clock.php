<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * 시각을 주입받는다.
 *
 * 코인 만료·재시도 백오프·어트리뷰션 판정이 전부 "지금이 언제인가"에 달려 있다.
 * time() 을 직접 부르면 그 규칙들을 테스트할 수 없다.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
