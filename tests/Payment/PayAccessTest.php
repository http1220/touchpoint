<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\PayAccess;
use PHPUnit\Framework\TestCase;

final class PayAccessTest extends TestCase
{
    public function test_토큰이_맞으면_열리고_쿠키로_이어진다(): void
    {
        self::assertTrue(PayAccess::tokenMatches('s3cret', 's3cret'));
        self::assertTrue(PayAccess::allows(PayAccess::cookieValue('s3cret'), 's3cret'));
    }

    public function test_쿠키에_토큰_원문을_넣어도_열리지_않는다(): void
    {
        self::assertFalse(PayAccess::allows('s3cret', 's3cret'));
    }

    /** 설정 누락은 닫힌 쪽으로 넘어진다 */
    public function test_비밀이_비면_아무도_못_연다(): void
    {
        self::assertFalse(PayAccess::tokenMatches('', ''));
        self::assertFalse(PayAccess::allows(PayAccess::cookieValue(''), ''));
        self::assertFalse(PayAccess::allows('', ''));
    }

    public function test_배열이나_null_은_거절한다(): void
    {
        self::assertFalse(PayAccess::tokenMatches(['s3cret'], 's3cret'));
        self::assertFalse(PayAccess::tokenMatches(null, 's3cret'));
        self::assertFalse(PayAccess::allows(null, 's3cret'));
    }
}
