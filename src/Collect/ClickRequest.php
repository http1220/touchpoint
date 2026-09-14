<?php

declare(strict_types=1);

namespace App\Collect;

use DateTimeImmutable;
use DateTimeZone;

/**
 * `GET /click?w=&s=&sd=&u=` — 기록하고 302 로 목적지에 보낸다.
 *
 * ── GET 인데 상태를 바꾼다 ──
 *
 * RFC 9110 의 safe method 규약 위반이다. 그래도 클릭 추적은 링크여야
 * 하고(자바스크립트 없이, 새 탭으로, 복사해서 붙여도 동작) 링크는 GET 이다.
 * 업계 관행이 이쪽인 이유다. 대신 GET 이라 생기는 문제를 하나씩 막는다.
 *
 *   프리페치·크롤러·링크 미리보기가 누른다   → 봇 UA 는 기록하지 않는다
 *   뒤로 가기·새로고침으로 두 번 눌린다       → (방문, 작품, 자리, sd) 로 한 번만
 *   중간 캐시가 302 를 재사용한다             → no-store (컨트롤러)
 *   목적지를 아무 URL 로 바꿔 피싱에 쓴다    → 우리 도메인 https 만 (오픈 리다이렉트)
 *
 * ── sd 는 왜 URL 에 있나 ──
 *
 * **노출된 날**이다. 링크를 그린 서버가 박는다. 23:59 에 본 배너를 00:01 에
 * 누르면, 클릭 시각 기준으로는 노출은 어제·클릭은 오늘에 잡혀 두 날 CTR 이
 * 다 틀린다. 노출 날짜를 링크에 실어 두면 클릭이 노출과 같은 날로 집계된다.
 * 믿을 수 없는 값이라(사용자가 고칠 수 있다) 오늘 기준 ±범위 밖이면 버린다.
 */
final class ClickRequest
{
    /** 링크를 오래 열어 둔 탭까지는 인정한다. 그보다 오래된 sd 는 조작이거나 캐시다. */
    public const SD_MAX_AGE_DAYS = 7;

    private const BOT_UA = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|curl|wget|python-requests|headless/i';

    private function __construct(
        public readonly ?string $destination,
        public readonly ?int $workId,
        public readonly ?string $slot,
        /** Y-m-d */
        public readonly string $statDate,
        public readonly bool $isBot,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, string $userAgent, string $shopDomain, DateTimeImmutable $now): self
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'));

        return new self(
            self::destination($query['u'] ?? null, $shopDomain),
            self::workId($query['w'] ?? null),
            Slot::normalize($query['s'] ?? null),
            self::statDate($query['sd'] ?? null, $today),
            trim($userAgent) === '' || preg_match(self::BOT_UA, $userAgent) === 1,
        );
    }

    /** 기록할 가치가 있는가. 목적지가 틀려도, 봇이어도 기록하지 않는다. */
    public function shouldRecord(): bool
    {
        return $this->destination !== null && !$this->isBot && $this->workId !== null && $this->slot !== null;
    }

    /** 같은 방문의 같은 날 같은 자리 같은 작품 클릭은 하나다. */
    public function dedupKey(int $visitId): string
    {
        return sha1(implode('|', ['click', $visitId, $this->workId, $this->slot, $this->statDate]));
    }

    /**
     * 목적지. **쿼리는 살린다** — 랜딩의 vid·utm 이 거기 실린다.
     * (매체로 보낼 페이지 주소를 다룰 때 쿼리를 버리는 ClientContext 와 반대다.
     *  그쪽은 남에게 넘기는 값이고, 이쪽은 사용자가 가려던 곳이다.)
     */
    private static function destination(mixed $raw, string $shopDomain): ?string
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 2048) {
            return null;
        }

        $shop = strtolower(trim($shopDomain));
        $u = parse_url($raw);

        if ($shop === '' || !is_array($u) || strtolower((string) ($u['scheme'] ?? '')) !== 'https'
            || isset($u['user']) || isset($u['pass']) || isset($u['port'])
            || preg_match('/[\x00-\x20\\\\]/', $raw) === 1) {
            return null;
        }

        $host = strtolower((string) ($u['host'] ?? ''));

        if ($host !== $shop && !str_ends_with($host, '.'.$shop)) {
            return null;
        }

        return 'https://'.$host.($u['path'] ?? '/').(isset($u['query']) ? '?'.$u['query'] : '');
    }

    private static function workId(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/\A[1-9]\d{0,17}\z/', $raw) === 1 ? (int) $raw : null;
    }

    private static function statDate(mixed $raw, DateTimeImmutable $today): string
    {
        $fallback = $today->format('Y-m-d');

        if (!is_string($raw) || preg_match('/\A\d{8}\z/', $raw) !== 1) {
            return $fallback;
        }

        $d = DateTimeImmutable::createFromFormat('!Ymd', $raw, new DateTimeZone('UTC'));

        if ($d === false || $d->format('Ymd') !== $raw) {
            return $fallback;
        }

        $days = (int) floor(($today->setTime(0, 0)->getTimestamp() - $d->getTimestamp()) / 86400);

        // 미래(시계 차 하루까지는 허용)이거나 너무 오래됐으면 오늘로.
        return ($days < -1 || $days > self::SD_MAX_AGE_DAYS) ? $fallback : $d->format('Y-m-d');
    }
}
