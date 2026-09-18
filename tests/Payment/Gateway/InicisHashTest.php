<?php

declare(strict_types=1);

namespace App\Tests\Payment\Gateway;

use App\Payment\Gateway\InicisHash;
use PHPUnit\Framework\TestCase;

/**
 * 대조 벡터 셋. **출처가 서로 다르고, 그걸 이름에 적는다.**
 *
 * 처음에 signature 벡터를 "매뉴얼 원문" 이라고 적었는데, 실제로는 검색 요약의
 * 값을 다시 해시해 본 순환 확인이었다. 원문을 받아 보니 매뉴얼의 예시 해시는
 * 매뉴얼의 예시 평문과 맞지 않았다 → docs/worklog.md 2026-09-19 막힌 것 1
 *
 * 여기 쓴 키는 이니시스가 공개했던 **테스트 전용** 자격 증명이다(INIpayTest).
 */
final class InicisHashTest extends TestCase
{
    /** 이니시스가 예전 매뉴얼에 공개한 테스트 MID 의 signKey */
    private const TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    /** 같은 매뉴얼의 테스트 MID INIAPI key */
    private const TEST_INIAPI_KEY = 'ItEQKi3rY7uvDS8l';

    public function test_signature_는_이니시스_해시_도구의_출력과_같다(): void
    {
        // 평문 oid=INIpayTest_1361252896871&price=1004&timestamp=1361252896871 을
        // manual.inicis.com/pay/demo/hash-sha256.php 에 넣은 결과 (2026-09-19).
        // 매뉴얼 원문의 예시 해시 ec1e9c63… 는 이 평문에서 나오지 않는다.
        self::assertSame(
            '422a0e78529b419d9412d6e344c6e138584d9174c691da6cd91d4330240b9192',
            InicisHash::signature('INIpayTest_1361252896871', 1004, 1361252896871)
        );
    }

    public function test_mKey_는_매뉴얼_원문과_같다(): void
    {
        // std-info (2022-07 아카이브): "PlainText: SU5J… / Hash 데이터: 3a9503…"
        self::assertSame(
            '3a9503069192f207491d4b19bd743fc249a761ed94246c8c42fed06c3cd15a33',
            InicisHash::mKey(self::TEST_SIGN_KEY)
        );
    }

    public function test_INIAPI_환불_해시는_매뉴얼_원문과_같다(): void
    {
        // iniapi/api-info (2021-06 아카이브)의 "카드취소 기준" 예시 그대로
        self::assertSame(
            'b2dc4d4308d836a77187fa1f4ce8c540006a41e6a708a63aded363510c7d445600601c9035825fe32f48fe1b7d2ea130f690a2895a41b6fa0a99c6c5f92d6d69',
            InicisHash::refund(
                self::TEST_INIAPI_KEY,
                'Refund',
                'Card',
                '20191128121211',
                '123.123.123.123',
                'INIpayTest',
                'StdpayCARDINIpayTest20191128121211123456'
            )
        );
    }

    public function test_verification_은_알파벳순으로_signKey_를_끼운다(): void
    {
        // 벡터가 없는 해시는 평문 규칙을 못박는다. 대소문자는 스테이징이 판정한다.
        self::assertSame(
            hash('sha256', 'oid=A&price=1000&signKey=K&timestamp=5'),
            InicisHash::verification('A', 1000, 'K', 5)
        );
        self::assertSame(hash('sha256', 'authToken=T&timestamp=5'), InicisHash::authSignature('T', 5));
        self::assertSame(hash('sha256', 'authToken=T&signKey=K&timestamp=5'), InicisHash::authVerification('T', 'K', 5));
    }
}
