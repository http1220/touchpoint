<?php

declare(strict_types=1);

namespace App\Tests\Payment\Gateway;

use App\Payment\Gateway\InicisEndpoints;
use PHPUnit\Framework\TestCase;

/**
 * authUrl 검사는 보안 경계다 — 뚫리면 가짜 승인으로 코인이 공짜다(C1).
 * 그래서 **통과해야 할 것보다 막아야 할 것을 더 많이** 적는다.
 */
final class InicisEndpointsTest extends TestCase
{
    /** @dataProvider 승인_URL */
    public function test_승인_URL_은_모드와_센터의_호스트만_받는다(string $url, string $mode, string $idc, bool $expected): void
    {
        self::assertSame($expected, InicisEndpoints::isApprovalUrl($url, $mode, $idc));
    }

    public static function 승인_URL(): array
    {
        return [
            '스테이징 정상'          => ['https://stgstdpay.inicis.com/api/payAuth', 'test', 'stg', true],
            '운영 fc 정상'           => ['https://fcstdpay.inicis.com/api/payAuth', 'live', 'fc', true],
            '운영 ks 정상'           => ['https://ksstdpay.inicis.com/api/payAuth', 'live', 'ks', true],
            '명시 443 포트'          => ['https://stgstdpay.inicis.com:443/api/payAuth', 'test', 'stg', true],

            '공격자 호스트'          => ['https://evil.example/api/payAuth', 'test', 'stg', false],
            '뒤에 붙인 도메인'        => ['https://stgstdpay.inicis.com.evil.example/x', 'test', 'stg', false],
            '비슷한 이름'            => ['https://evilstgstdpay.inicis.com/x', 'test', 'stg', false],
            'http'                  => ['http://stgstdpay.inicis.com/api/payAuth', 'test', 'stg', false],
            '사용자 정보 끼우기'      => ['https://stgstdpay.inicis.com@evil.example/x', 'test', 'stg', false],
            '다른 포트'              => ['https://stgstdpay.inicis.com:8443/x', 'test', 'stg', false],
            '센터와 호스트 불일치'     => ['https://ksstdpay.inicis.com/api/payAuth', 'live', 'fc', false],
            '테스트 모드에 운영 센터'  => ['https://fcstdpay.inicis.com/api/payAuth', 'test', 'fc', false],
            '운영 모드에 스테이징'     => ['https://stgstdpay.inicis.com/api/payAuth', 'live', 'stg', false],
            '모르는 센터'            => ['https://stgstdpay.inicis.com/api/payAuth', 'test', 'xx', false],
            '빈 문자열'              => ['', 'test', 'stg', false],
            '상대 경로'              => ['/api/payAuth', 'test', 'stg', false],
        ];
    }

    public function test_망취소_URL_은_모드_안의_호스트면_센터가_달라도_받는다(): void
    {
        // [추정] 망취소 호스트가 승인과 같은 센터라는 문장이 원문에 없다 — InicisEndpoints 주석
        self::assertTrue(InicisEndpoints::isNetCancelUrl('https://ksstdpay.inicis.com/api/netCancel', 'live'));
        self::assertTrue(InicisEndpoints::isNetCancelUrl('https://fcstdpay.inicis.com/api/netCancel', 'live'));
        self::assertFalse(InicisEndpoints::isNetCancelUrl('https://stgstdpay.inicis.com/api/netCancel', 'live'));
        self::assertFalse(InicisEndpoints::isNetCancelUrl('https://evil.example/api/netCancel', 'test'));
    }

    public function test_모드마다_JS_와_환불_호스트가_갈린다(): void
    {
        self::assertSame('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', InicisEndpoints::js('test'));
        self::assertSame('https://stdpay.inicis.com/stdjs/INIStdPay.js', InicisEndpoints::js('live'));
        self::assertSame('https://stginiapi.inicis.com/api/v1/refund', InicisEndpoints::refundUrl('test'));
        self::assertSame('https://iniapi.inicis.com/api/v1/refund', InicisEndpoints::refundUrl('live'));
    }
}
