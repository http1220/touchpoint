<?php

declare(strict_types=1);

namespace App\Tests\Channel;

use App\Channel\GaClientId;
use PHPUnit\Framework\TestCase;

final class GaClientIdTest extends TestCase
{
    /** @dataProvider 쿠키값 */
    public function test_쿠키에서_client_id_를_뽑는다(?string $cookie, ?string $expected): void
    {
        self::assertSame($expected, GaClientId::fromCookie($cookie));
    }

    public static function 쿠키값(): array
    {
        return [
            '일반(깊이 1)'  => ['GA1.1.1234567890.1700000000', '1234567890.1700000000'],
            '깊이 2'        => ['GA1.2.987654321.1699999999', '987654321.1699999999'],
            '공백 포함'     => ['  GA1.1.111.222  ', '111.222'],

            // 앞의 두 마디는 쿠키 형식 메타데이터다. 붙여 보내면
            // GA4 가 다른 사용자로 센다 — 그래서 반드시 떼어낸다.
            '접두를 남기지 않는다' => ['GA1.1.555.666', '555.666'],

            '빈 값'         => ['', null],
            '없음'          => [null, null],
            '접두 없음'     => ['1234567890.1700000000', null],
            '마디 부족'     => ['GA1.1.1234567890', null],
            '숫자 아님'     => ['GA1.1.abc.def', null],
            '뒤에 덧붙음'   => ['GA1.1.111.222.333', null],
            '다른 쿠키'     => ['GS1.1.111.222', null],
        ];
    }

    public function test_뽑은_값에_GA_접두가_남아있지_않다(): void
    {
        $got = GaClientId::fromCookie('GA1.1.1234567890.1700000000');

        self::assertIsString($got);
        self::assertStringStartsNotWith('GA', $got);
    }

    public function test_대체값은_형식을_유지한다(): void
    {
        $got = GaClientId::fallback('01a08c02db59781d9a4425880d006636', 1757673600);

        // <숫자>.<숫자> 꼴이어야 도구들이 덜 놀란다.
        self::assertMatchesRegularExpression('/\A\d+\.\d+\z/', $got);
        self::assertStringEndsWith('.1757673600', $got);
    }

    public function test_같은_방문이면_대체값도_같다(): void
    {
        // 요청마다 값이 달라지면 한 방문이 여러 사용자로 쪼개진다.
        $a = GaClientId::fallback('01a08c02db59781d9a4425880d006636', 1757673600);
        $b = GaClientId::fallback('01a08c02db59781d9a4425880d006636', 1757673600);

        self::assertSame($a, $b);
    }

    public function test_방문이_다르면_대체값도_다르다(): void
    {
        $a = GaClientId::fallback('01a08c02db59781d9a4425880d006636', 1757673600);
        $b = GaClientId::fallback('ffffffff0000000000000000deadbeef', 1757673600);

        self::assertNotSame($a, $b);
    }
}
