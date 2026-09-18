<?php

declare(strict_types=1);

namespace App\Payment\Tax;

/**
 * 세금을 매기지 않고 그대로 통과시킨다. **판단을 미룬 것이지 "세금이 없다" 는 판단이 아니다.**
 *
 * 그 차이를 결과에 남긴다 — reason 이 'out-of-scope' 다. 나중에 실제 정책이
 * 들어오면, 이 정책으로 결제된 행을 골라 다시 볼 수 있어야 한다.
 * → docs/plan-multi-pg.md 1장 A
 */
final class PassThroughTaxPolicy implements TaxPolicy
{
    public const NAME = 'pass-through';

    public const REASON = 'out-of-scope';

    public function name(): string
    {
        return self::NAME;
    }

    public function quote(int $amountMinor, string $currency, ?string $buyerCountry): TaxQuote
    {
        // $buyerCountry 는 읽지 않는다. 받는 자리만 있다 — 과세지를 판정하는 순간 범위 밖이다.
        return TaxQuote::untaxed($amountMinor, $currency, self::NAME, self::REASON);
    }
}
