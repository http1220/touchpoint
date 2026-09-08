<?php

declare(strict_types=1);

namespace App\Coin;

/**
 * 어느 지급 건에서 얼마를 뺄 것인가.
 *
 * 실제 차감은 모델이 트랜잭션 안에서 한다. 여기서는 계획만 세운다.
 */
final class SpendPlan
{
    public function __construct(
        public readonly int $lotId,
        public readonly int $amount,
    ) {
    }
}
