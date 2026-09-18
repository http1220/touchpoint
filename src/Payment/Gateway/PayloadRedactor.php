<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * PG 응답에서 **남길 필드만** 고른다. 허용 목록이지 차단 목록이 아니다.
 *
 * 원문을 그대로 payment_events.raw_payload 에 넣으면 카드번호(부분 마스킹)와
 * 구매자 이름·전화·이메일이 **5년 동안 보존**된다 — 결제 기록 보존 의무 때문에
 * 그 행은 지울 수 없다 → docs/plan-multi-pg.md C5 · 6장
 *
 * 차단 목록으로 하면 PG 가 응답 필드를 하나 늘리는 날 그 필드가 조용히 저장된다.
 * 이니시스 매뉴얼은 "응답파라미터는 추후 요건에 의해 추가될 수 있습니다" 라고
 * 적어 두었다. 그래서 허용 목록이다.
 *
 * 대가: **허용 목록 밖 필드는 나중에 분쟁이 생겨도 복원할 수 없다.** 대신 원문의
 * sha256 을 남겨 "그때 받은 바이트가 이것이었다" 는 대조는 할 수 있게 한다.
 */
final class PayloadRedactor
{
    public const HASH_KEY = '_raw_sha256';

    /**
     * @param array<string, mixed> $data    파싱한 응답
     * @param list<string>         $allowed 남길 키
     * @param string               $raw     응답 원문 바이트
     * @return array<string, mixed>
     */
    public static function keep(array $data, array $allowed, string $raw): array
    {
        $out = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) {
                $out[$key] = $data[$key];
            }
        }

        $out[self::HASH_KEY] = hash('sha256', $raw);

        return $out;
    }
}
