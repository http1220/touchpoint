<?php

declare(strict_types=1);

namespace App\Attribution;

/**
 * 이번 요청으로 first / last 접점을 어떻게 바꿀 것인가.
 *
 * 저장은 호출자(모델)가 한다. 여기서는 판단만 담아 넘긴다.
 */
final class Resolution
{
    private function __construct(
        public readonly ?Touchpoint $createFirst,
        public readonly ?Touchpoint $upsertLast,
    ) {
    }

    public static function nothing(): self
    {
        return new self(null, null);
    }

    public static function lastOnly(Touchpoint $last): self
    {
        return new self(null, $last);
    }

    public static function firstAndLast(Touchpoint $touch): self
    {
        return new self($touch, $touch);
    }

    public function changesAnything(): bool
    {
        return $this->createFirst !== null || $this->upsertLast !== null;
    }
}
