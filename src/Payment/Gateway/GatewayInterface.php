<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * PG 하나. 이니시스·페이팔이 구현한다 → docs/plan-multi-pg.md 2장
 *
 * ── 하나로 모으는 자리는 "웹훅 엔진" 이 아니다 ──
 *
 * 이니시스 카드 결제에는 서버 간 웹훅이 없다. 결과는 브라우저가 복귀 URL 로
 * 가져오고, 우리가 승인을 **동기로** 요청한다(B3). 페이팔은 동기 capture 와
 * 비동기 웹훅이 둘 다 온다. 그래서 이 인터페이스는 웹훅을 모르고,
 * 결과는 모두 GatewayResult 로 나와 **같은 CAS 전이**(Payment_model::applyEvent)
 * 로 들어간다. 중복·역전 방어는 D-3 에서 이미 쟀다.
 *
 * ── ChannelInterface 와 다른 점 ──
 *
 * 매체 전송은 워커가 하고 웹 요청 안에서는 외부 HTTP 를 부르지 않는다
 * (CurlHttpClient 주석). **PG 승인은 그 규칙을 지킬 수 없다** — 사용자가
 * 복귀 페이지에서 기다리고 있고, 승인이 나야 코인을 줄 수 있다.
 * 두 번째 PG 가 깨뜨린 설계 중 하나다 → plan-multi-pg.md B11
 */
interface GatewayInterface
{
    /** payments.pg · payment_pg_refs.pg 에 들어가는 이름 */
    public function name(): string;

    /** ISO 4217 */
    public function supports(string $currency): bool;

    /**
     * 브라우저가 결제창을 여는 데 필요한 값. **서명은 서버가 한다.**
     *
     * @return array{action: string, fields: array<string, string>}
     */
    public function checkout(Checkout $checkout): array;

    /**
     * 브라우저가 가져온 결과를 PG 에 확정 요청한다.
     *
     * 금액·통화·uid 는 **우리 행에서** 넘긴다. 브라우저가 보낸 값을 믿지 않고
     * PG 응답과 대조하는 기준으로 쓴다.
     *
     * @param array<string, mixed> $input 복귀 요청 본문 등
     */
    public function confirm(array $input, string $paymentUid, int $amountMinor, string $currency): GatewayResult;

    /**
     * confirm 이 만든 승인을 되돌린다. **장부에 반영하지 못했을 때만** 부른다.
     *
     * 일반 환불과 다르다 — 이니시스 원문: "망취소를 일반 결제취소 용도로
     * 사용하지 마십시오." 되돌렸으면 true.
     */
    public function compensate(GatewayResult $confirmed, int $amountMinor): bool;

    /**
     * 확정된 결제를 환불한다(전체).
     *
     * @param array<string, string> $refs payment_pg_refs 에서 읽은 것
     */
    public function refund(array $refs, string $reason): GatewayResult;
}
