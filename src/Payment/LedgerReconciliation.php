<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 결제 ↔ 코인 ↔ 전환 대사.
 *
 * ── 왜 ──
 *
 * 2022-10 카카오페이 데이터센터 화재 때 "계좌에서는 빠졌는데 송금 내역에도
 * 없고 받은 사람도 없다" 가 신고됐고, 회사는 피해 규모를 파악하지 못했다
 * → docs/incidents/payment-incidents.md 4
 *
 * 이 프로젝트에도 같은 모양이 있다. 결제는 captured 인데 코인이 없거나,
 * 매체에 매출이 안 갔거나, 반대로 코인은 있는데 결제가 확정되지 않았다.
 * 매체 쪽 대사(App\Verify\Reconciliation)는 있었지만 **내부 장부끼리의
 * 대사는 없었다.** 사고가 나면 "몇 건이 어긋났는가" 에 답할 도구가 없었다.
 *
 * DB 를 모른다. 호출자가 세 목록을 읽어 넘긴다 → cli/Verify::payments
 */
final class LedgerReconciliation
{
    /** 돈은 받았는데 코인이 없다. 카카오페이 사고의 모양 */
    public const COIN_MISSING = 'coin_missing';

    /** 돈은 받았는데 상품표에 없는 금액이라 코인을 못 줬다. 사람이 메워야 한다 */
    public const UNKNOWN_PRICE = 'unknown_price';

    /** 한 결제에 코인이 두 번 지급됐다. 웹훅 중복 처리의 모양(D-3 대조군) */
    public const COIN_DUPLICATED = 'coin_duplicated';

    /** 확정되지 않은 결제에 코인이 있다 */
    public const UNPAID_GRANT = 'unpaid_grant';

    /** 확정된 결제가 구매 전환으로 기록되지 않았다 — 매체에 매출이 안 간다 */
    public const PURCHASE_NOT_REPORTED = 'purchase_not_reported';

    /** 환불됐는데 매체에 보낼 환불 전환이 없다 — 매체에 매출이 남는다 */
    public const REFUND_NOT_REPORTED = 'refund_not_reported';

    /** 환불됐는데 코인이 남아 있다 */
    public const REVOCATION_MISSING = 'revocation_missing';

    private const PAID = [PaymentStatus::CAPTURED, PaymentStatus::REFUNDED];

    /**
     * @param list<array{uid: string, kind: string, detail: string}> $issues
     */
    private function __construct(
        public readonly int $checked,
        public readonly array $issues,
    ) {
    }

    /**
     * @param list<array{uid: string, status: string, amount_minor: int, currency: string}> $payments
     * @param array<string, list<array{amount: int, remaining: int, revoked: bool}>>       $lotsByPayment
     * @param array<string, array{purchase?: bool, refund?: bool}>                          $conversionsByPayment
     */
    public static function of(array $payments, array $lotsByPayment, array $conversionsByPayment): self
    {
        $issues = [];

        foreach ($payments as $p) {
            $uid = $p['uid'];
            $status = $p['status'];
            $paid = in_array($status, self::PAID, true);
            $lots = $lotsByPayment[$uid] ?? [];
            $conv = $conversionsByPayment[$uid] ?? [];

            if ($paid && $lots === []) {
                $known = CoinProduct::findByPrice((int) $p['amount_minor'], (string) $p['currency']) !== null;
                $issues[] = self::issue(
                    $uid,
                    $known ? self::COIN_MISSING : self::UNKNOWN_PRICE,
                    $known
                        ? $status.' 인데 지급된 코인이 없다'
                        : $status.' 인데 상품표에 없는 금액('.$p['amount_minor'].' '.$p['currency'].')이라 코인이 없다'
                );
            }

            if (count($lots) > 1) {
                $issues[] = self::issue($uid, self::COIN_DUPLICATED, '코인이 '.count($lots).'번 지급됐다');
            }

            if (!$paid && $lots !== []) {
                $issues[] = self::issue($uid, self::UNPAID_GRANT, $status.' 인데 코인이 지급돼 있다');
            }

            if ($paid && empty($conv['purchase'])) {
                $issues[] = self::issue($uid, self::PURCHASE_NOT_REPORTED, $status.' 인데 구매 전환이 없다');
            }

            if ($status === PaymentStatus::REFUNDED) {
                // 가리킬 구매 전환이 없으면 환불 전환도 만들지 않는다(Payment_model::recordRefund).
                // 그 경우는 위의 PURCHASE_NOT_REPORTED 로 이미 잡혔다.
                if (!empty($conv['purchase']) && empty($conv['refund'])) {
                    $issues[] = self::issue($uid, self::REFUND_NOT_REPORTED, '환불됐는데 환불 전환이 없다');
                }

                foreach ($lots as $lot) {
                    // revoked_at 이 생기기 전에 회수된 lot 은 표시 없이 remaining 0 이다.
                    // 남은 코인이 없으면 회수 누락이 아니다.
                    if (!$lot['revoked'] && $lot['remaining'] > 0) {
                        $issues[] = self::issue($uid, self::REVOCATION_MISSING, '환불됐는데 코인 '.$lot['remaining'].'개가 남아 있다');
                    }
                }
            }
        }

        return new self(count($payments), $issues);
    }

    public function isClean(): bool
    {
        return $this->issues === [];
    }

    /** @return array<string, int> 종류별 건수 */
    public function counts(): array
    {
        $out = [];

        foreach ($this->issues as $i) {
            $out[$i['kind']] = ($out[$i['kind']] ?? 0) + 1;
        }

        ksort($out);

        return $out;
    }

    /** @return array{uid: string, kind: string, detail: string} */
    private static function issue(string $uid, string $kind, string $detail): array
    {
        return ['uid' => $uid, 'kind' => $kind, 'detail' => $detail];
    }
}
