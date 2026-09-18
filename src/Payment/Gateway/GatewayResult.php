<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * PG 에 무엇인가를 요청한 결과. 승인(confirm)과 환불(refund)이 같이 쓴다.
 *
 * ── outcome ──
 *
 *   captured   승인됐다. 우리 장부에 captured 로 반영한다
 *   pending    PG 가 보류했다(페이팔 capture PENDING 등). pending 으로 반영한다
 *   failed     **PG 가** 승인하지 않았다. failed 로 반영한다
 *   rejected   **PG 에 묻기 전에 우리가** 거절했다. 장부를 바꾸지 않는다
 *   unknown    **됐는지 모른다** — 응답을 못 받았다(타임아웃·연결 끊김)
 *   refunded   환불됐다 (refund() 만 돌려준다)
 *
 * failed 와 rejected 를 가르는 이유: rejected 의 근거는 **브라우저가 보낸 값**이다
 * (위조된 authUrl · 다른 주문번호). 그걸로 결제를 failed 로 바꾸면 payment_uid 를
 * 아는 공격자가 위조 복귀 요청 하나로 **남의 결제를 실패시킬 수 있다.** PG 가
 * 말한 것만 장부를 바꾼다.
 *
 * unknown 을 failed 로 뭉개지 않는 것이 이 타입의 핵심이다. 승인 요청이
 * 타임아웃됐을 때 PG 쪽에서는 승인이 났을 수 있다. 그걸 failed 로 적고
 * 끝내면 **돈은 빠졌는데 코인이 없다** → docs/plan-multi-pg.md B4
 *
 * ── compensation ──
 *
 * 승인을 되돌리는 데 필요한 값(이니시스는 망취소 URL · authToken).
 * **저장하지 않는다** — 한 요청 안에서만 쓰고 버린다. authToken 을 DB 에
 * 남기면 그 자체가 재사용 가능한 자격이 된다.
 *
 * mustCompensate() 가 참이면 호출자는 **즉시** compensate() 를 불러야 한다.
 * PG 에 승인이 남아 있을 수 있는데 우리는 그걸 장부에 올리지 않기로 한
 * 경우다(응답 없음 · 금액 불일치).
 */
final class GatewayResult
{
    public const CAPTURED = 'captured';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';
    public const REFUNDED = 'refunded';

    /**
     * @param array<string, string> $refs         PG 참조 ID. ref_type => 값 (tid · order · capture)
     * @param array<string, mixed>  $stored       payment_events.raw_payload 에 남길 것. 허용 목록을 거쳤다
     * @param array<string, string> $compensation 되돌리기에 필요한 값. 저장 금지
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $refs,
        public readonly array $stored,
        /** 사람이 읽을 이유. 로그와 이벤트에 남긴다 */
        public readonly ?string $reason,
        public readonly array $compensation,
    ) {
    }

    /** @param array<string, string> $refs @param array<string, mixed> $stored @param array<string, string> $compensation */
    public static function captured(array $refs, array $stored, array $compensation = []): self
    {
        return new self(self::CAPTURED, $refs, $stored, null, $compensation);
    }

    /** @param array<string, string> $refs @param array<string, mixed> $stored */
    public static function pending(array $refs, array $stored): self
    {
        return new self(self::PENDING, $refs, $stored, null, []);
    }

    /**
     * 승인되지 않았다.
     *
     * $compensation 을 주면 "PG 쪽엔 승인이 났는데 우리가 거절한다" 는 뜻이다
     * (금액 불일치). 그때는 mustCompensate() 가 참이 된다.
     *
     * @param array<string, mixed> $stored @param array<string, string> $compensation
     */
    public static function failed(string $reason, array $stored = [], array $compensation = []): self
    {
        return new self(self::FAILED, [], $stored, $reason, $compensation);
    }

    /**
     * PG 에 묻기 전에 우리가 거절했다. PG 쪽에 승인이 없고, 장부도 바꾸지 않는다.
     *
     * @param array<string, mixed> $stored
     */
    public static function rejected(string $reason, array $stored = []): self
    {
        return new self(self::REJECTED, [], $stored, $reason, []);
    }

    /** @param array<string, string> $compensation */
    public static function unknown(string $reason, array $compensation = []): self
    {
        return new self(self::UNKNOWN, [], [], $reason, $compensation);
    }

    /** @param array<string, mixed> $stored */
    public static function refunded(array $stored): self
    {
        return new self(self::REFUNDED, [], $stored, null, []);
    }

    public function isCaptured(): bool
    {
        return $this->outcome === self::CAPTURED;
    }

    /** PG 에 승인이 남아 있을 수 있는데 장부에 올리지 않는다 → 지금 되돌려야 한다 */
    public function mustCompensate(): bool
    {
        return $this->outcome !== self::CAPTURED
            && $this->outcome !== self::REFUNDED
            && $this->compensation !== [];
    }
}
