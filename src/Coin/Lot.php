<?php

declare(strict_types=1);

namespace App\Coin;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 코인 지급 건 하나.
 *
 * 잔액을 숫자 하나로 두지 않는 이유는 유료와 무료의 유효기간이 다르기 때문이다.
 * 관측된 약관은 유료 5년, 무료·이벤트 1년이었다. 두 종류를 한 숫자로 합치면
 * 어느 코인이 언제 만료되는지 복원할 수 없다.
 */
final class Lot
{
    public const KIND_PAID = 'paid';
    public const KIND_FREE = 'free';

    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly int $remaining,
        public readonly DateTimeImmutable $expiresAt,
    ) {
        if ($kind !== self::KIND_PAID && $kind !== self::KIND_FREE) {
            throw new InvalidArgumentException("알 수 없는 코인 종류: {$kind}");
        }

        if ($remaining < 0) {
            throw new InvalidArgumentException('잔여 수량은 음수가 될 수 없습니다.');
        }
    }

    public function isFree(): bool
    {
        return $this->kind === self::KIND_FREE;
    }

    /**
     * 지금 쓸 수 있는가.
     *
     * 만료는 배치로 차감하지 않고 조회 시점에 판정한다.
     * 배치가 숫자를 고치는 구조면 배치가 실패할 때마다 잔액이 틀어진다.
     */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return $this->remaining > 0 && $this->expiresAt > $now;
    }
}
