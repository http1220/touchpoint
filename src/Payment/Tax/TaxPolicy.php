<?php

declare(strict_types=1);

namespace App\Payment\Tax;

/**
 * 결제 한 건에 붙는 세금을 정하는 자리. **세무 판단은 이 프로젝트의 범위 밖이다.**
 *
 * PG 를 직접 붙이면 우리가 판매자(seller of record)가 되고, 해외 B2C 디지털
 * 재화의 현지 소비세를 누가 신고·납부하는지가 우리 쪽 문제로 넘어온다
 * → docs/plan-multi-pg.md 1장 A. 그 판단(세율·과세지·세금 포함가 여부·
 * 구매자 소재지 증빙)은 세무 전문가의 몫이라 여기서 하지 않는다.
 *
 * 그래도 **자리는 만든다.** 나중에 규칙이 생겼을 때 결제 흐름 전체를 뒤지지
 * 않고 구현체 하나만 바꾸면 되도록 — ChannelInterface 와 같은 이유다.
 * 지금 구현체는 PassThroughTaxPolicy 하나이고, 금액을 바꾸지 않는다.
 *
 * **아직 어디서도 부르지 않는다.** PG 조립(Gateways)을 만들 때 붙인다
 * → docs/plan-multi-pg.md 2장
 */
interface TaxPolicy
{
    /** 이 정책의 이름. 어떤 규칙으로 계산했는지를 결제 기록에 남길 때 쓴다 */
    public function name(): string;

    /**
     * 상품표 금액에 세금을 적용한 결과.
     *
     * 세금이 없어도 **반드시 TaxQuote 를 돌려준다.** "계산하지 않았다" 와
     * "계산해 보니 0 이다" 가 기록에서 구분돼야 한다 — 그래서 결과에
     * policy 와 reason 이 붙는다.
     *
     * @param int         $amountMinor  상품표 금액 (minor unit)
     * @param string      $currency     ISO 4217 alpha-3
     * @param string|null $buyerCountry ISO 3166-1 alpha-2. 모르면 null.
     *                                  과세지 판정에 필요한 입력이라 자리만 둔다
     */
    public function quote(int $amountMinor, string $currency, ?string $buyerCountry): TaxQuote;
}
