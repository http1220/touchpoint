<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Accept-Language 헤더에서 언어 코드 하나를 뽑는다.
 *
 * `visits.lang` 은 CHAR(2) 이고 ISO 639-1 이다. 헤더는 그보다 훨씬
 * 자유로운 형식이라(BCP 47 + 품질값) 그대로 넣을 수 없다.
 *
 *   ko-KR,ko;q=0.9,en-US;q=0.8   →  ko
 *   zh-Hant-TW,zh;q=0.9          →  zh
 *   *                            →  null
 *
 * 지역·표기(Hant/Hans)는 버린다. 이 프로젝트에서 lang 은 집계 축이고,
 * URL 슬러그와 hreflang 은 별도 locales 테이블이 담당한다.
 * → docs/data-model.md 6장
 *
 * 값을 믿지 않는 이유: 이 헤더는 클라이언트가 자유롭게 보낸다.
 * 길이 제한과 형식 검사가 없으면 DB 에 들어가는 순간 잘리거나 실패한다.
 */
final class AcceptLanguage
{
    /** 너무 긴 헤더는 앞부분만 본다. 뒤쪽은 어차피 품질값이 낮다. */
    private const MAX_INPUT = 512;

    private function __construct()
    {
    }

    /**
     * 품질값이 가장 높은 언어의 2자 코드. 없으면 null.
     *
     * q 값이 같으면 먼저 나온 쪽이 이긴다 — RFC 9110 의 순서 규칙과 같다.
     */
    public static function primary(?string $header): ?string
    {
        $header = substr(trim((string) $header), 0, self::MAX_INPUT);

        if ($header === '') {
            return null;
        }

        $best = null;
        $bestQ = -1.0;

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', $part);
            $tag = strtolower(trim($bits[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            // 앞의 언어 서브태그만. zh-Hant-TW → zh
            $lang = strtolower(explode('-', $tag)[0]);

            if (preg_match('/\A[a-z]{2}\z/', $lang) !== 1) {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/\Aq=([0-9]*\.?[0-9]+)\z/', trim($param), $m) === 1) {
                    $q = (float) $m[1];
                }
            }

            if ($q > $bestQ) {
                $best = $lang;
                $bestQ = $q;
            }
        }

        return $best;
    }
}
