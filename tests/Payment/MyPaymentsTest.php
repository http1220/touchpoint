<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\MyPayments;
use PHPUnit\Framework\TestCase;

final class MyPaymentsTest extends TestCase
{
    private const A = '01a0b7b50b80728c9d1e2f3a4b5c6d7e';
    private const B = '01a0b7ccbb7e7f00a1b2c3d4e5f60718';

    public function test_새_결제가_맨_앞에_온다(): void
    {
        $raw = MyPayments::add('', self::A);
        $raw = MyPayments::add($raw, self::B);

        self::assertSame([self::B, self::A], MyPayments::parse($raw));
    }

    public function test_같은_결제를_다시_넣으면_앞으로_옮길_뿐_늘지_않는다(): void
    {
        $raw = MyPayments::add(self::A.'.'.self::B, self::B);

        self::assertSame([self::B, self::A], MyPayments::parse($raw));
    }

    public function test_넘치면_가장_오래된_것부터_버린다(): void
    {
        $raw = '';

        for ($i = 0; $i < MyPayments::MAX + 5; $i++) {
            $raw = MyPayments::add($raw, sprintf('%032x', $i));
        }

        $list = MyPayments::parse($raw);

        self::assertCount(MyPayments::MAX, $list);
        self::assertSame(sprintf('%032x', MyPayments::MAX + 4), $list[0]);   // 가장 최근
        self::assertNotContains(sprintf('%032x', 0), $list);                  // 가장 오래된 것
        self::assertLessThan(4096, strlen($raw));
    }

    /** @dataProvider 망가진_값 */
    public function test_형식이_틀린_조각은_버린다(mixed $raw, array $expected): void
    {
        self::assertSame($expected, MyPayments::parse($raw));
    }

    public static function 망가진_값(): array
    {
        return [
            '쿠키 없음'   => [null, []],
            '빈 값'      => ['', []],
            '배열'       => [['x'], []],
            '짧은 조각'  => ['abc.'.self::A, [self::A]],
            '따옴표 주입' => ['"><script>.'.self::B, [self::B]],
            '대문자'     => [strtoupper(self::A), [self::A]],
            '중복'       => [self::A.'.'.self::A, [self::A]],
        ];
    }

    public function test_uid_가_아니면_넣지_않는다(): void
    {
        self::assertSame(self::A, MyPayments::add(self::A, 'not-a-uid'));
    }
}
