<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 홈의 ?lang= 판정.
 *
 * 정본 URL 은 로케일마다 하나다. 같은 화면이 여러 주소로 열리면 hreflang 이
 * 어느 쪽을 가리키는지 알 수 없게 된다 — 그래서 받아들일 수 없는 값은 그리지 않고
 * 정본으로 돌려보낸다.
 *
 *   (없음)              기본 로케일을 그린다
 *   ja   (활성)         ja 를 그린다
 *   ko   (기본)         / 로 보낸다       — 기본 로케일의 정본은 파라미터 없는 / 다
 *   JA · " ja"          ?lang=ja 로 보낸다 — 대소문자·공백만 다르면 뜻은 살린다
 *   zh-hans (비활성) · xx · 빈 값 · 배열 · 긴 값     / 로 보낸다
 *
 * 값을 믿지 않는 이유는 AcceptLanguage 와 같다 — 쿼리는 누구나 손으로 바꾼다.
 * 활성 목록은 DB(locales.is_active)에서 온다. 언어를 켜고 끄는 데 코드를 고치지 않는다.
 */
final class LocaleChoice
{
    /** slug 는 VARCHAR(8) 이다. 그보다 긴 값은 볼 필요가 없다. */
    private const MAX_INPUT = 16;

    /**
     * @param string      $slug     그릴 로케일
     * @param string|null $redirect 보낼 곳의 쿼리. null 이면 그대로 그린다, '' 이면 / 로
     */
    private function __construct(
        public readonly string $slug,
        public readonly ?string $redirect,
    ) {
    }

    /**
     * @param mixed    $raw     $_GET['lang'] 그대로 — 없으면 null, 배열일 수도 있다
     * @param string[] $active  활성 로케일 slug
     */
    public static function from(mixed $raw, array $active, string $default): self
    {
        if ($raw === null) {
            return new self($default, null);
        }

        if (! is_string($raw) || strlen($raw) > self::MAX_INPUT) {
            return new self($default, '');
        }

        // 공백·탭만 지운다. trim() 기본값은 널 바이트까지 지워 "ja\0" 을 ja 로 살려 버린다
        $slug = strtolower(trim($raw, " \t"));

        if ($slug === $default || ! in_array($slug, $active, true)) {
            return new self($default, '');
        }

        if ($slug !== $raw) {
            return new self($slug, 'lang='.$slug);
        }

        return new self($slug, null);
    }
}
