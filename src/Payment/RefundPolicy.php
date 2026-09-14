<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 환불 웹훅이 판정해야 하는 두 가지. DB 없이 판정할 수 있어 여기 둔다.
 *
 * ── ① 캡처보다 먼저 온 환불 ──
 *
 * PG 입장에서 환불은 캡처 뒤에만 일어난다. 그런데 **웹훅 도착 순서는
 * 사건 순서가 아니다.** captured 가 재시도 대기에 걸려 있는 사이
 * refunded 가 먼저 도착할 수 있다.
 *
 * 다른 역전(authorized 뒤에 온 pending)은 "무시 + 200" 이 맞다 — 과거
 * 사건이라 버려도 잃는 것이 없다. **환불은 다르다.** 여기서 무시하고
 * 200 을 주면 PG 는 재전송하지 않고, 뒤이어 captured 가 오면 결제는
 * captured 로 남는다. 돈은 돌려줬는데 코인과 매출이 살아 있다.
 *
 * 그래서 이 경우만 **재시도를 요청한다(409).** 캡처가 도착한 뒤의
 * 재전송에서 정상 처리된다.
 *
 * ── ② 이미 쓴 코인 ──
 *
 * 약관 규칙은 "이용 내역이 없을 때만 청약철회" 다 → ADR-006 파생 규칙.
 * 하지만 그 규칙은 **환불을 요청받을 때** 우리가 거는 조건이고, 웹훅은
 * **이미 일어난 환불의 통지**다. PG 가 돈을 돌려줬다는 사실은 거절할 수
 * 없다. 남은 코인만 회수하고, 쓴 만큼은 사람이 봐야 할 불일치로 남긴다.
 * 잔액을 음수로 만들지 않는다 — 다른 결제로 산 코인을 대신 깎는 셈이다.
 */
final class RefundPolicy
{
    /**
     * 지금 상태에서 이 환불을 적용하기엔 이른가.
     *
     * 종결(failed·refunded)이면 이르지 않다 — 그건 늦은 것이고, 전이표가
     * 무시로 처리한다.
     */
    public static function arrivedBeforeCapture(string $current, string $to): bool
    {
        if ($to !== PaymentStatus::REFUNDED) {
            return false;
        }

        $rank = PaymentStatus::rank($current);

        return $rank !== null && $rank < PaymentStatus::rank(PaymentStatus::CAPTURED);
    }

    /**
     * lot 하나에서 회수할 코인과 이미 쓴 코인.
     *
     * @return array{revoke: int, spent: int}
     */
    public static function revocation(int $amount, int $remaining): array
    {
        $remaining = max(0, min($amount, $remaining));

        return ['revoke' => $remaining, 'spent' => $amount - $remaining];
    }
}
