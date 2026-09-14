<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function test_아는_상태와_모르는_상태(): void
    {
        foreach (PaymentStatus::ALL as $status) {
            self::assertTrue(PaymentStatus::isKnown($status), $status);
        }

        // 오타 하나가 CAS 의 WHERE 를 영원히 0행으로 만든다.
        self::assertFalse(PaymentStatus::isKnown('authorised'));
        self::assertFalse(PaymentStatus::isKnown('CAPTURED'));
        self::assertFalse(PaymentStatus::isKnown(''));
    }

    /** 상태 이름은 payments.status VARCHAR(24) 에 들어간다. */
    public function test_상태_이름이_컬럼_길이를_넘지_않는다(): void
    {
        foreach (PaymentStatus::ALL as $status) {
            self::assertLessThanOrEqual(24, strlen($status), $status);
        }
    }

    /**
     * **종결 상태에는 서열이 없다.**
     *
     * 실패는 성공보다 앞도 뒤도 아니다. 숫자를 억지로 주면 비교가
     * 성립해 버리고, 성립하는 비교는 언젠가 판정에 쓰인다 — 판정은
     * PaymentStateMachine 한 곳이어야 한다.
     */
    public function test_진행_축의_서열(): void
    {
        self::assertSame(0, PaymentStatus::rank('created'));
        self::assertSame(1, PaymentStatus::rank('pending'));
        self::assertSame(2, PaymentStatus::rank('authorized'));
        self::assertSame(3, PaymentStatus::rank('captured'));

        self::assertNull(PaymentStatus::rank('failed'));
        self::assertNull(PaymentStatus::rank('refunded'));
        self::assertNull(PaymentStatus::rank('모르는 값'));
    }

    public function test_종결_상태(): void
    {
        self::assertTrue(PaymentStatus::isTerminal('failed'));
        self::assertTrue(PaymentStatus::isTerminal('refunded'));

        self::assertFalse(PaymentStatus::isTerminal('created'));
        self::assertFalse(PaymentStatus::isTerminal('pending'));
        self::assertFalse(PaymentStatus::isTerminal('authorized'));
        self::assertFalse(PaymentStatus::isTerminal('captured'));
    }
}
