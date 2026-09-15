<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\LogFile;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LogFileTest extends TestCase
{
    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function test_이름은_SAPI_와_UTC_날짜로_나뉜다(): void
    {
        self::assertSame('app-fpm-fcgi-2026-09-15.jsonl', LogFile::name('fpm-fcgi', $this->utc('2026-09-15 23:59:59')));
        self::assertSame('app-cli-2026-09-15.jsonl', LogFile::name('cli', $this->utc('2026-09-15 00:00:00')));

        // 한국 시각 09-16 08:00 은 UTC 로 09-15 다
        $kst = new DateTimeImmutable('2026-09-16 08:00:00', new DateTimeZone('Asia/Seoul'));
        self::assertSame('app-cli-2026-09-15.jsonl', LogFile::name('cli', $kst));
    }

    public function test_이상한_SAPI_이름은_파일명에서_무해해진다(): void
    {
        self::assertSame('app----etc-passwd-2026-09-15.jsonl', LogFile::name('../etc/passwd', $this->utc('2026-09-15')));
    }

    /** @dataProvider 만료 */
    public function test_이름의_날짜로_보존기간을_판정한다(string $file, bool $expired): void
    {
        self::assertSame($expired, LogFile::isExpired($file, $this->utc('2026-06-17 12:00:00')));
    }

    public static function 만료(): array
    {
        return [
            '기준일 이틀 전'      => ['app-cli-2026-06-15.jsonl', true],
            '기준일 전날(끝남)'   => ['app-fpm-fcgi-2026-06-16.jsonl', true],
            '기준일 당일(진행 중)' => ['app-cli-2026-06-17.jsonl', false],
            '최근'              => ['app-cli-2026-09-15.jsonl', false],
            '모르는 이름'         => ['purge.log', false],
            '없는 날짜'          => ['app-cli-2026-02-31.jsonl', false],
            '확장자 다름'         => ['app-cli-2026-06-15.log', false],
        ];
    }
}
