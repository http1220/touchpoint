<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\LocaleChoice;
use PHPUnit\Framework\TestCase;

final class LocaleChoiceTest extends TestCase
{
    private const ACTIVE = ['en', 'ja', 'ko'];

    /** @dataProvider 입력 */
    public function test_판정(mixed $raw, string $slug, ?string $redirect): void
    {
        $c = LocaleChoice::from($raw, self::ACTIVE, 'ko');

        self::assertSame($slug, $c->slug, 'slug');
        self::assertSame($redirect, $c->redirect, 'redirect');
    }

    public static function 입력(): array
    {
        return [
            // 그린다
            '파라미터 없음'        => [null, 'ko', null],
            '활성 ja'             => ['ja', 'ja', null],
            '활성 en'             => ['en', 'en', null],

            // 기본 로케일의 정본은 / 다 — ?lang=ko 로 두 주소가 생기지 않게
            '기본 ko 는 / 로'      => ['ko', 'ko', ''],

            // 뜻은 살리고 주소만 정본으로
            '대문자'              => ['JA', 'ja', 'lang=ja'],
            '앞뒤 공백'            => [' ja ', 'ja', 'lang=ja'],
            '대문자 기본'          => ['KO', 'ko', ''],

            // 받아들일 수 없다 — 그리지 않고 / 로
            '비활성 zh-hans'       => ['zh-hans', 'ko', ''],
            '모르는 값'            => ['xx', 'ko', ''],
            '빈 값'               => ['', 'ko', ''],
            '배열 lang[]=ja'       => [['ja'], 'ko', ''],
            '긴 값'               => [str_repeat('j', 17), 'ko', ''],
            '경로 조작'            => ['../ja', 'ko', ''],
            '널 바이트'            => ["ja\0", 'ko', ''],
        ];
    }

    public function test_활성_목록이_비어도_기본을_그린다(): void
    {
        $c = LocaleChoice::from('ja', [], 'ko');

        self::assertSame('ko', $c->slug);
        self::assertSame('', $c->redirect);
    }
}
