<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * 이니시스 해시. 전부 순수 함수다.
 *
 * 규칙은 매뉴얼 원문에서 왔다 → docs/worklog.md 2026-09-19
 *
 *   "NVP 방식으로 연결한 데이터를 SHA256으로 Hash"
 *   "필드 순서 유지(알파벳순), 마지막 &는 생략, 공백생략"
 *
 * 대조 벡터 셋이 테스트에 있다. **출처가 서로 다르다** — signature 는 이니시스
 * 공개 해시 도구의 출력이고(매뉴얼 예시 해시가 매뉴얼 예시 평문과 맞지 않는다),
 * mKey 와 INIAPI 환불 해시는 매뉴얼 원문 값이다.
 *
 * [미확인] 검증용 평문의 필드 이름 `signKey` 의 대소문자. 공식 샘플은 가맹점
 * 로그인 뒤에 있어 보지 못했다. 알파벳순 규칙상 p 다음 s 라 위치는 맞다.
 * 판정은 스테이징 결제창이 한다 → plan-multi-pg.md 6장 「무과금 확인」
 */
final class InicisHash
{
    /** 결제 요청 signature. 대상: oid, price, timestamp */
    public static function signature(string $oid, int $price, int $timestamp): string
    {
        return hash('sha256', 'oid='.$oid.'&price='.$price.'&timestamp='.$timestamp);
    }

    /** 결제 요청 verification. 대상: oid, price, signKey, timestamp */
    public static function verification(string $oid, int $price, string $signKey, int $timestamp): string
    {
        return hash('sha256', 'oid='.$oid.'&price='.$price.'&signKey='.$signKey.'&timestamp='.$timestamp);
    }

    /** mid 와 짝인 signKey 의 SHA256 */
    public static function mKey(string $signKey): string
    {
        return hash('sha256', $signKey);
    }

    /** 승인·망취소 요청 signature. 대상: authToken, timestamp */
    public static function authSignature(string $authToken, int $timestamp): string
    {
        return hash('sha256', 'authToken='.$authToken.'&timestamp='.$timestamp);
    }

    /** 승인·망취소 요청 verification. 대상: authToken, signKey, timestamp */
    public static function authVerification(string $authToken, string $signKey, int $timestamp): string
    {
        return hash('sha256', 'authToken='.$authToken.'&signKey='.$signKey.'&timestamp='.$timestamp);
    }

    /**
     * INIAPI 전체취소 hashData.
     *
     * 원문: "SHA512 HASH 한 값 대상 : INIAPIKey + type + paymethod + timestamp + clientIp + mid + tid"
     * **구분자 없이 이어 붙인다** — NVP 가 아니다. 결제창 쪽과 규칙이 다르다.
     */
    public static function refund(
        string $iniapiKey,
        string $type,
        string $paymethod,
        string $timestamp,
        string $clientIp,
        string $mid,
        string $tid,
    ): string {
        return hash('sha512', $iniapiKey.$type.$paymethod.$timestamp.$clientIp.$mid.$tid);
    }
}
