<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 결제 상태 전이표.
 *
 * `allowedFrom()` 하나가 본체다. 돌려주는 배열이 **그대로** CAS 의
 * `WHERE status IN (…)` 목록이 된다 — 표와 SQL 이 같은 자리에서 나오지
 * 않으면 둘 중 하나만 고쳐지는 날이 온다.
 * → docs/plan-payment-webhook.md 2·3장
 *
 * **건너뛴 전진을 허용하는 것이 이 표의 유일한 판단이다.**
 * `captured` 가 `authorized` 보다 먼저 도착했을 때 "순서가 틀렸으니 무시"
 * 하면 우리는 200 을 돌려주고, PG 는 재전송하지 않고, 결제 완료가 영원히
 * 사라진다. 막아야 할 것은 순서가 아니라 **과거가 미래를 덮는 것**이다.
 * 건너뛴 사실은 payment_events 에 `from='created', to='captured'` 로 남는다.
 *
 * 판정을 여기(프레임워크 밖)에 두는 이유는 CorsPolicy 와 같다 → ADR-017
 */
final class PaymentStateMachine
{
    /**
     * to => 허용된 from 들.
     *
     * 여기 없는 조합은 전부 무시다. 표에 없는 to(`created`)는 전이의
     * 목적지가 아니다 — created 는 /purchase 의 INSERT 로만 생긴다.
     *
     * @var array<string, list<string>>
     */
    private const TABLE = [
        PaymentStatus::PENDING => [PaymentStatus::CREATED],
        PaymentStatus::AUTHORIZED => [PaymentStatus::CREATED, PaymentStatus::PENDING],
        PaymentStatus::CAPTURED => [PaymentStatus::CREATED, PaymentStatus::PENDING, PaymentStatus::AUTHORIZED],
        PaymentStatus::FAILED => [PaymentStatus::CREATED, PaymentStatus::PENDING, PaymentStatus::AUTHORIZED],

        // 환불은 captured 에서만. 그보다 앞선 상태에 도착한 환불은 "무시" 가
        // 아니라 "재시도 요청" 이다 — 표가 아니라 RefundPolicy 가 가른다.
        // 코인 회수·매체 환불 전송은 Payment_model::onRefunded (09-15)
        PaymentStatus::REFUNDED => [PaymentStatus::CAPTURED],
    ];

    /**
     * 이 상태로 갈 수 있는 출발점들.
     *
     * **빈 배열은 "아무 데서도 못 온다" 는 뜻**이고, 호출자는 그걸 그대로
     * SQL 에 넣으면 안 된다 — `IN ()` 은 MySQL 구문 오류다. 부르는 쪽이
     * 빈 배열을 먼저 걸러 "전이 없음" 으로 끝내야 한다.
     *
     * @return list<string>
     */
    public function allowedFrom(string $to): array
    {
        return self::TABLE[$to] ?? [];
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($from, $this->allowedFrom($to), true);
    }

    /**
     * 표에 목적지로 등장하는 상태들.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return array_keys(self::TABLE);
    }
}
