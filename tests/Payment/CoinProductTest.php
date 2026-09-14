<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\CoinProduct;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CoinProductTest extends TestCase
{
    public function test_상품표에서_찾는다(): void
    {
        $product = CoinProduct::find('coin_100');

        self::assertNotNull($product);
        self::assertSame('coin_100', $product->code);
        self::assertSame(100, $product->coins);
        self::assertSame(9900, $product->amountMinor);
        self::assertSame('KRW', $product->currency);
    }

    /** @dataProvider 없는코드 */
    public function test_표에_없는_코드는_null_이다(mixed $code): void
    {
        self::assertNull(CoinProduct::find($code));
    }

    public static function 없는코드(): array
    {
        return [
            '모르는 상품' => ['coin_9999'],
            '빈 값' => [''],
            '없음' => [null],
            '대문자' => ['COIN_100'],
            '배열' => [['coin_100']],
            '숫자' => [100],
        ];
    }

    /**
     * **클라이언트가 금액을 정하면 그건 결제 조작이다.**
     *
     * 본문의 금액은 대조만 하고, 어긋나면 422 다 → 계획 1장
     *
     * @dataProvider 대조
     */
    public function test_금액과_통화를_상품표와_대조한다(?int $amount, ?string $currency, bool $expected): void
    {
        self::assertSame($expected, CoinProduct::find('coin_100')->matches($amount, $currency));
    }

    public static function 대조(): array
    {
        return [
            '일치' => [9900, 'KRW', true],
            '소문자 통화' => [9900, 'krw', true],
            '싸게 사려는 시도' => [100, 'KRW', false],
            '비싸게' => [99000, 'KRW', false],
            '통화만 다름' => [9900, 'USD', false],

            // 안 보내면 서버 값으로 채워 주는 편의를 두면 그 경로로는
            // 대조가 아예 일어나지 않는다. 누락도 불일치다.
            '금액 누락' => [null, 'KRW', false],
            '통화 누락' => [9900, null, false],
            '둘 다 누락' => [null, null, false],
        ];
    }

    /**
     * `captured` 시점에는 상품 코드가 어디에도 없다 — payments 스키마에
     * 칸이 없고 웹훅 본문에도 안 온다. 우리가 기록한 금액으로 되찾는다.
     */
    public function test_금액으로_상품을_되찾는다(): void
    {
        $product = CoinProduct::findByPrice(9900, 'KRW');

        self::assertNotNull($product);
        self::assertSame('coin_100', $product->code);
        self::assertSame(100, $product->coins);

        self::assertNull(CoinProduct::findByPrice(9901, 'KRW'));
        self::assertNull(CoinProduct::findByPrice(9900, 'USD'));
    }

    /**
     * **금액은 통화 안에서 유일해야 한다.**
     *
     * 겹치는 상품을 추가하면 findByPrice() 가 먼저 걸린 쪽을 돌려주고,
     * 적립되는 코인 수가 조용히 달라진다. 그때 이 테스트가 깨진다.
     */
    public function test_금액이_겹치는_상품이_없다(): void
    {
        $seen = [];

        foreach (CoinProduct::codes() as $code) {
            $p = CoinProduct::find($code);
            $key = $p->currency.':'.$p->amountMinor;

            self::assertArrayNotHasKey($key, $seen, $key.' 가 '.($seen[$key] ?? '').' 와 겹친다');
            $seen[$key] = $code;
        }

        self::assertNotSame([], $seen);
    }

    /** 모든 상품이 왕복한다 — find() → 금액 → findByPrice() 가 같은 코드로 돌아온다. */
    public function test_모든_상품이_금액으로_왕복한다(): void
    {
        foreach (CoinProduct::codes() as $code) {
            $p = CoinProduct::find($code);

            self::assertSame($code, CoinProduct::findByPrice($p->amountMinor, $p->currency)->code);
            self::assertGreaterThan(0, $p->coins);
            self::assertGreaterThan(0, $p->amountMinor);
        }
    }

    /** 유료 코인 5년 → ADR-006. 이 규칙은 UI 어디에도 없고 약관에만 있다. */
    public function test_유료_코인은_5년_뒤_만료된다(): void
    {
        $granted = new DateTimeImmutable('2026-09-14 12:00:00.000', new DateTimeZone('UTC'));

        self::assertSame(
            '2031-09-14 12:00:00.000',
            CoinProduct::find('coin_100')->expiresAt($granted)->format('Y-m-d H:i:s.v')
        );
    }
}
