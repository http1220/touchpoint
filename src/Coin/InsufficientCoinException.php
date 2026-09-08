<?php

declare(strict_types=1);

namespace App\Coin;

use RuntimeException;

final class InsufficientCoinException extends RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("코인이 부족합니다. 필요 {$requested}, 사용 가능 {$available}");
    }
}
