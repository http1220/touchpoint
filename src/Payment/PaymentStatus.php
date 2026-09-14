<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 결제 상태의 이름표.
 *
 * `payments.status` 는 VARCHAR(24) 라 DB 가 오타를 막아 주지 않는다.
 * 문자열 리터럴을 코드 곳곳에 흩어 두면 `'authorised'` 한 번으로
 * **CAS 의 WHERE 가 영원히 0행을 돌려주고**, 그건 "웹훅이 무시됐다" 와
 * 구분되지 않는다. 이름은 여기 한 곳에만 둔다.
 *
 * 서열(rank)은 2장 전이표를 **만든 근거**지 판정 기준이 아니다.
 * `rank(to) > rank(from)` 같은 비교로 판정을 다시 쓰면 표와 코드가
 * 갈라진다 — 판정은 PaymentStateMachine::allowedFrom() 한 곳이다.
 */
final class PaymentStatus
{
    public const CREATED = 'created';
    public const PENDING = 'pending';
    public const AUTHORIZED = 'authorized';
    public const CAPTURED = 'captured';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';

    /** 이 목록과 PaymentStateMachine 의 표는 함께 움직인다. */
    public const ALL = [
        self::CREATED,
        self::PENDING,
        self::AUTHORIZED,
        self::CAPTURED,
        self::FAILED,
        self::REFUNDED,
    ];

    /**
     * 정상 진행 축의 서열.
     *
     * 종결 상태(failed·refunded)는 이 축 위에 없다 — 실패는 성공보다
     * "앞" 도 "뒤" 도 아니다. 숫자를 억지로 주면 비교가 성립해 버리고,
     * 성립하는 비교는 언젠가 판정에 쓰인다.
     */
    private const RANK = [
        self::CREATED => 0,
        self::PENDING => 1,
        self::AUTHORIZED => 2,
        self::CAPTURED => 3,
    ];

    /** 더 나아갈 곳이 없는 상태. */
    private const TERMINAL = [self::FAILED, self::REFUNDED];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** 진행 축 밖(종결·미지)이면 null. 없는 것을 0 으로 때우지 않는다. */
    public static function rank(string $status): ?int
    {
        return self::RANK[$status] ?? null;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
