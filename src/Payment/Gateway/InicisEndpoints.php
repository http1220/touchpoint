<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * 이니시스 호스트와 "이 URL 로 요청해도 되는가".
 *
 * ── 왜 이게 보안 경계인가 ──
 *
 * 승인 요청 URL(`authUrl`)과 망취소 URL(`netCancelUrl`)은 **브라우저가 POST 로
 * 실어 온다.** 공격자가 복귀 요청을 직접 만들면 두 값을 마음대로 적을 수 있다.
 * 검사하지 않고 그 URL 로 승인을 요청하면 가짜 승인 서버가 "0000" 을 돌려주고
 * **코인이 공짜로 지급된다.** 우리 서버가 임의 URL 로 요청을 보내는 SSRF 이기도
 * 하다 → docs/plan-multi-pg.md C1
 *
 * 매뉴얼 원문: "이니시스 제공 승인API 가 맞는지 확인 필요 (IDC센터코드와 비교 검증 필요)"
 *
 * ── 호스트 표 (원문 방화벽 정보) ──
 *
 *   스테이징  stgstdpay.inicis.com                       idc_name = stg
 *   운영      fcstdpay.inicis.com · ksstdpay.inicis.com   idc_name = fc · ks
 *
 * **모드와 센터를 함께 본다.** 테스트 모드인데 운영 센터(fc)가 오면 거절한다 —
 * 테스트 키로 운영 승인이 나는 상황은 어느 쪽이든 설정 사고다 → D5
 */
final class InicisEndpoints
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    /** 모드 → [idc_name => 승인 호스트] */
    private const APPROVAL_HOSTS = [
        self::MODE_TEST => ['stg' => 'stgstdpay.inicis.com'],
        self::MODE_LIVE => ['fc' => 'fcstdpay.inicis.com', 'ks' => 'ksstdpay.inicis.com'],
    ];

    /** 원문: "상용JS (테스트JS 에서 stg 제거)" */
    private const JS = [
        self::MODE_TEST => 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js',
        self::MODE_LIVE => 'https://stdpay.inicis.com/stdjs/INIStdPay.js',
    ];

    /** INIAPI. 원문 방화벽 정보: 스테이징은 "테스트MID 만 사용가능" */
    private const INIAPI = [
        self::MODE_TEST => 'https://stginiapi.inicis.com',
        self::MODE_LIVE => 'https://iniapi.inicis.com',
    ];

    public static function isMode(string $mode): bool
    {
        return isset(self::APPROVAL_HOSTS[$mode]);
    }

    public static function js(string $mode): string
    {
        return self::JS[$mode];
    }

    public static function refundUrl(string $mode): string
    {
        return self::INIAPI[$mode].'/api/v1/refund';
    }

    /** 이 모드에서 이 센터의 승인 호스트. 모르는 조합이면 null */
    public static function approvalHost(string $mode, string $idcName): ?string
    {
        return self::APPROVAL_HOSTS[$mode][$idcName] ?? null;
    }

    /**
     * 승인 URL 이 이 센터의 호스트인가.
     *
     * 호스트 **완전 일치**만 본다. `endsWith('.inicis.com')` 은
     * `stgstdpay.inicis.com.evil.example` 이나 `evilinicis.com` 에 뚫린다.
     */
    public static function isApprovalUrl(string $url, string $mode, string $idcName): bool
    {
        $host = self::approvalHost($mode, $idcName);

        return $host !== null && self::hostIs($url, $host);
    }

    /**
     * 망취소 URL 이 이 모드의 승인 호스트 중 하나인가.
     *
     * [추정] 망취소 호스트가 승인과 같은 센터라는 문장은 원문에 없다. 센터가
     * 다를 가능성을 막지 않도록 **모드 안의 호스트 전체**를 허용한다. 여기서
     * 너무 좁히면 망취소를 못 보내고, 그건 "돈은 빠졌는데 코인이 없다" 다.
     */
    public static function isNetCancelUrl(string $url, string $mode): bool
    {
        foreach (self::APPROVAL_HOSTS[$mode] ?? [] as $host) {
            if (self::hostIs($url, $host)) {
                return true;
            }
        }

        return false;
    }

    private static function hostIs(string $url, string $host): bool
    {
        $p = parse_url($url);

        if (!is_array($p)) {
            return false;
        }

        return ($p['scheme'] ?? '') === 'https'
            && strtolower($p['host'] ?? '') === $host
            && !isset($p['user'])
            && !isset($p['pass'])
            && (!isset($p['port']) || $p['port'] === 443);
    }
}
