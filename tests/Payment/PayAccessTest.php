<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\PayAccess;
use PHPUnit\Framework\TestCase;

final class PayAccessTest extends TestCase
{
    private const SECRET = 's3cret-s3cret-s3cret-s3cret-0001';
    private const NOW = 1_789_800_000;

    public function test_발급한_입장권은_만료_전까지_열린다(): void
    {
        $t = PayAccess::issue(self::SECRET, self::NOW);

        self::assertTrue(PayAccess::allows($t, self::SECRET, self::NOW));
        self::assertTrue(PayAccess::allows($t, self::SECRET, self::NOW + PayAccess::TTL - 1));
        self::assertFalse(PayAccess::allows($t, self::SECRET, self::NOW + PayAccess::TTL), '만료 시각에 닫힌다');
    }

    public function test_방문자마다_다른_입장권이다(): void
    {
        self::assertNotSame(PayAccess::issue(self::SECRET, self::NOW), PayAccess::issue(self::SECRET, self::NOW));
    }

    /** @dataProvider 위조 */
    public function test_위조한_입장권은_열리지_않는다(string $ticket): void
    {
        self::assertFalse(PayAccess::allows($ticket, self::SECRET, self::NOW));
    }

    public static function 위조(): array
    {
        $good = PayAccess::issue(self::SECRET, self::NOW, str_repeat('ab', 16));
        [$v, $exp, $nonce, $sig] = explode('.', $good);

        return [
            '만료를 늘림'       => ["$v.".((int) $exp + 999999).".$nonce.$sig"],
            '다른 비밀로 서명'   => [PayAccess::issue('other-secret', self::NOW, $nonce)],
            '서명 한 글자 바꿈'  => ["$v.$exp.$nonce.".substr($sig, 0, -1).($sig[-1] === 'a' ? 'b' : 'a')],
            '버전 바꿈'         => ["v2.$exp.$nonce.$sig"],
            '토막'             => ["$v.$exp.$nonce"],
            '비밀 원문을 쿠키로' => [self::SECRET],
            '빈 문자열'         => [''],
        ];
    }

    /** 설정 누락은 닫힌 쪽으로 넘어진다 */
    public function test_비밀이_비면_아무도_못_연다(): void
    {
        self::assertFalse(PayAccess::tokenMatches('', ''));
        self::assertFalse(PayAccess::allows('v1.9999999999.'.str_repeat('0', 32).'.'.hash_hmac('sha256', 'v1.9999999999.'.str_repeat('0', 32), ''), '', self::NOW));

        $this->expectException(\InvalidArgumentException::class);
        PayAccess::issue('', self::NOW);
    }

    public function test_운영자_지름길은_비밀과_같아야_한다(): void
    {
        self::assertTrue(PayAccess::tokenMatches(self::SECRET, self::SECRET));
        self::assertFalse(PayAccess::tokenMatches('wrong', self::SECRET));
        self::assertFalse(PayAccess::tokenMatches(['x'], self::SECRET));
        self::assertFalse(PayAccess::allows(null, self::SECRET, self::NOW));
    }
}
