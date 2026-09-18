<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * 결제창을 여는 데 필요한 것. 금액은 **우리 payments 행**에서 온다.
 *
 * 구매자 이름·휴대폰·이메일은 이니시스 결제 요청의 필수 값이다(`buyername*`
 * `buyertel*` `buyeremail*`). 이 프로젝트는 가입이 없어서 **시연용 고정값**을
 * 넣는다(.env) — 실제 개인정보가 우리 서버를 지나지 않는다
 * → docs/plan-multi-pg.md B9 · 6장
 */
final class Checkout
{
    public function __construct(
        /** payments.payment_uid (32자 hex). 이니시스 oid(40 byte) 안에 들어간다 */
        public readonly string $paymentUid,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $goodName,
        public readonly string $buyerName,
        public readonly string $buyerTel,
        public readonly string $buyerEmail,
        /** 결과 수신. 원문: "결제요청페이지 도메인과 일치하도록" */
        public readonly string $returnUrl,
        public readonly string $closeUrl,
    ) {
    }
}
