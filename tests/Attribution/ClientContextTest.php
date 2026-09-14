<?php

declare(strict_types=1);

namespace App\Tests\Attribution;

use App\Attribution\ClientContext;
use PHPUnit\Framework\TestCase;

final class ClientContextTest extends TestCase
{
    /** @dataProvider 주소 */
    public function test_페이지_주소는_우리_도메인_https_만_받고_쿼리를_버린다(?string $in, ?string $expected): void
    {
        self::assertSame($expected, ClientContext::sourceUrl($in, 'example.com'));
    }

    public static function 주소(): array
    {
        return [
            '하위 도메인 · 쿼리·조각 제거' => ['https://app.example.com/coins/checkout?email=a@b.c#x', 'https://app.example.com/coins/checkout'],
            '루트 도메인'               => ['https://example.com/', 'https://example.com/'],
            '경로 없음 → /'            => ['https://lp.example.com', 'https://lp.example.com/'],
            '대문자 호스트'             => ['https://APP.Example.com/a', 'https://app.example.com/a'],
            'http 는 거절'             => ['http://app.example.com/', null],
            '다른 도메인'               => ['https://evil.com/', null],
            '접미사만 같은 도메인'        => ['https://notexample.com/', null],
            '호스트에 우리 도메인을 끼움'   => ['https://example.com.evil.com/', null],
            '사용자 정보 포함'           => ['https://u:p@app.example.com/', null],
            '포트 지정'                => ['https://app.example.com:8443/', null],
            '빈 값'                   => ['', null],
            'null'                   => [null, null],
        ];
    }

    public function test_도메인_설정이_비면_주소를_받지_않는다(): void
    {
        self::assertNull(ClientContext::sourceUrl('https://app.example.com/', ''));
    }

    public function test_IP_는_형식이_맞을_때만(): void
    {
        self::assertSame('203.0.113.7', ClientContext::ip('203.0.113.7'));
        self::assertSame('2001:db8::1', ClientContext::ip('2001:db8::1'));
        self::assertNull(ClientContext::ip('0.0.0.0'), 'CI3 가 형식 오류 때 주는 값');
        self::assertNull(ClientContext::ip('not-an-ip'));
    }

    public function test_UA_는_512자로_자른다(): void
    {
        self::assertSame(512, mb_strlen((string) ClientContext::userAgent(str_repeat('a', 600))));
        self::assertNull(ClientContext::userAgent('   '));
    }

    public function test_fbp_fbc_형식(): void
    {
        self::assertSame('fb.1.1757900000000.1234567890', ClientContext::fbp('fb.1.1757900000000.1234567890'));
        self::assertNull(ClientContext::fbp('not-valid'));
        self::assertSame('fb.1.1757900000000.IwAR2abc_-', ClientContext::fbc('fb.1.1757900000000.IwAR2abc_-'));
        self::assertNull(ClientContext::fbc('fb.1.1757900000000.'.str_repeat('a', 300)));
    }

    public function test_from_은_값이_있는_키만_돌려준다(): void
    {
        $ctx = ClientContext::from('Mozilla/5.0', '0.0.0.0', 'https://evil.com/', 'fb.1.1757900000000.1', null, 'example.com');

        self::assertSame(['client_user_agent' => 'Mozilla/5.0', 'fbp' => 'fb.1.1757900000000.1'], $ctx);
    }
}
