<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\AcceptLanguage;
use PHPUnit\Framework\TestCase;

final class AcceptLanguageTest extends TestCase
{
    /** @dataProvider 헤더 */
    public function test_언어코드를_뽑는다(?string $header, ?string $expected): void
    {
        self::assertSame($expected, AcceptLanguage::primary($header));
    }

    public static function 헤더(): array
    {
        return [
            '한국어 크롬'      => ['ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7', 'ko'],
            '단일'             => ['en', 'en'],
            '지역 포함'        => ['ja-JP', 'ja'],
            '표기 포함'        => ['zh-Hant-TW,zh;q=0.9', 'zh'],

            // q 값이 순서를 이긴다. 앞에 온다고 이기는 게 아니다.
            'q값이 순서를 이김' => ['en;q=0.5,ko;q=0.9', 'ko'],
            'q값 동률은 선착순'  => ['de;q=0.8,fr;q=0.8', 'de'],
            'q=0 은 진다'       => ['en;q=0,ko;q=0.1', 'ko'],

            '와일드카드만'     => ['*', null],
            '와일드카드 뒤에'   => ['*;q=0.5,ko', 'ko'],
            '빈 헤더'          => ['', null],
            '없음'             => [null, null],
            '공백만'           => ['   ', null],

            // 2자가 아닌 언어 태그(ISO 639-2)는 CHAR(2) 에 못 들어간다. 버린다.
            '3자 코드'         => ['fil', null],
            '3자 뒤에 2자'      => ['fil,ko;q=0.9', 'ko'],
            '쓰레기'           => ['!!!,,,;;;', null],
        ];
    }

    public function test_아주_긴_헤더도_죽지_않는다(): void
    {
        $header = str_repeat('en-US,', 5000).'ko';

        // 앞부분만 보므로 ko 까지 닿지 않는다. 그래도 예외 없이 값을 준다.
        self::assertSame('en', AcceptLanguage::primary($header));
    }

    public function test_결과는_언제나_두_글자거나_null_이다(): void
    {
        foreach (['ko-KR', 'zh-Hant', '*', '', 'x'] as $raw) {
            $got = AcceptLanguage::primary($raw);

            self::assertTrue($got === null || strlen($got) === 2, "입력: {$raw}");
        }
    }
}
