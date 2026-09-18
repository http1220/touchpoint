<?php

declare(strict_types=1);

namespace App\Payment\Tax;

/**
 * TaxPolicy 의 결과.
 *
 * **gross = net + tax 를 생성자가 보장한다.** 셋을 따로 받으면 언젠가
 * 셋이 어긋난 값이 만들어지고, 그건 청구액과 장부가 다르다는 뜻이다.
 * 그래서 net 과 tax 만 받고 gross 는 계산한다.
 *
 * 청구하는 금액은 gross 다. 상품표 가격을 세금 포함가로 볼지 별도가로 볼지는
 * 정책(구현체)이 정한다 — 그 선택 자체가 범위 밖의 세무 판단이다.
 */
final class TaxQuote
{
    public readonly int $grossMinor;

    private function __construct(
        public readonly int $netMinor,
        public readonly int $taxMinor,
        public readonly string $currency,
        /** 계산한 정책의 이름 (TaxPolicy::name) */
        public readonly string $policy,
        /** 과세지. 세금을 매기지 않았으면 null */
        public readonly ?string $jurisdiction,
        /** 이 값이 나온 이유. 사람이 읽는 기록이다 */
        public readonly string $reason,
    ) {
        $this->grossMinor = $netMinor + $taxMinor;
    }

    /** 세금을 매기지 않았다. 청구액 = 상품표 금액 */
    public static function untaxed(int $amountMinor, string $currency, string $policy, string $reason): self
    {
        return new self($amountMinor, 0, strtoupper($currency), $policy, null, $reason);
    }

    public function isTaxed(): bool
    {
        return $this->taxMinor !== 0;
    }
}
