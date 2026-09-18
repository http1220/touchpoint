<?php

declare(strict_types=1);

namespace App\Tests\Payment\Tax;

use App\Payment\Tax\PassThroughTaxPolicy;
use PHPUnit\Framework\TestCase;

final class PassThroughTaxPolicyTest extends TestCase
{
    /** @dataProvider 결제 */
    public function test_금액을_바꾸지_않는다(int $amount, string $currency, ?string $country): void
    {
        $quote = (new PassThroughTaxPolicy())->quote($amount, $currency, $country);

        self::assertSame($amount, $quote->grossMinor);
        self::assertSame($amount, $quote->netMinor);
        self::assertSame(0, $quote->taxMinor);
        self::assertFalse($quote->isTaxed());
        self::assertNull($quote->jurisdiction);
    }

    public static function 결제(): array
    {
        return [
            '국내 KRW'          => [9900, 'KRW', 'KR'],
            '해외 USD'          => [299, 'USD', 'DE'],
            '소재지 모름'        => [9900, 'KRW', null],
        ];
    }

    public function test_미룬_판단이라는_사실을_남긴다(): void
    {
        $quote = (new PassThroughTaxPolicy())->quote(299, 'usd', 'FR');

        // "세금 0" 이 계산 결과가 아니라 판단을 미룬 것임을 기록에서 가려낼 수 있어야 한다
        self::assertSame(PassThroughTaxPolicy::NAME, $quote->policy);
        self::assertSame(PassThroughTaxPolicy::REASON, $quote->reason);
        self::assertSame('USD', $quote->currency);
    }
}
