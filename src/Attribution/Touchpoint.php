<?php

declare(strict_types=1);

namespace App\Attribution;

use DateTimeImmutable;

/**
 * 유입 접점 하나.
 *
 * 분류 축은 관측된 대상 조직의 체계를 따른다 — pid / subpid / channel 3단.
 * 여기에 업계 표준 파라미터(utm_*, gclid, fbclid)를 함께 받는다.
 */
final class Touchpoint
{
    /** 컬럼 길이. 넘으면 자른다 — 잘못된 파라미터 하나로 insert 가 실패하면 유입 자체를 잃는다. */
    private const LIMITS = [
        'pid' => 64,
        'subpid' => 128,
        'channel' => 32,
        'utmSource' => 128,
        'utmMedium' => 128,
        'utmCampaign' => 128,
        'utmContent' => 128,
        'utmTerm' => 128,
        'gclid' => 255,
        'fbclid' => 255,
    ];

    /**
     * 소문자로 정규화하는 필드.
     *
     * 이 넷은 집계의 분류 축이라 'Google' 과 'google' 이 갈리면 통계가 쪼개진다.
     * 반면 campaign·content·term·subpid 는 자유 텍스트이고,
     * gclid·fbclid 는 매체가 발급한 식별자라 원문을 보존한다.
     */
    private const LOWERCASED = ['pid', 'channel', 'utmSource', 'utmMedium'];

    public function __construct(
        public readonly ?string $pid,
        public readonly ?string $subpid,
        public readonly ?string $channel,
        public readonly ?string $utmSource,
        public readonly ?string $utmMedium,
        public readonly ?string $utmCampaign,
        public readonly ?string $utmContent,
        public readonly ?string $utmTerm,
        public readonly ?string $gclid,
        public readonly ?string $fbclid,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * 쿼리스트링에서 만든다.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, DateTimeImmutable $occurredAt): self
    {
        $map = [
            'pid' => 'pid',
            'subpid' => 'subpid',
            'channel' => 'channel',
            'utmSource' => 'utm_source',
            'utmMedium' => 'utm_medium',
            'utmCampaign' => 'utm_campaign',
            'utmContent' => 'utm_content',
            'utmTerm' => 'utm_term',
            'gclid' => 'gclid',
            'fbclid' => 'fbclid',
        ];

        $values = [];
        foreach ($map as $field => $key) {
            $values[$field] = self::normalize($field, $query[$key] ?? null);
        }

        return new self(
            $values['pid'],
            $values['subpid'],
            $values['channel'],
            $values['utmSource'],
            $values['utmMedium'],
            $values['utmCampaign'],
            $values['utmContent'],
            $values['utmTerm'],
            $values['gclid'],
            $values['fbclid'],
            $occurredAt,
        );
    }

    /**
     * 유입 소스가 하나도 없는 접점.
     *
     * 직접 유입, 북마크, 앱 전환 등이 여기 해당한다.
     * 이런 접점은 first-touch 가 되지 않고 last-touch 도 덮어쓰지 않는다.
     * 판단 근거는 TouchpointResolver 에 적어 두었다.
     */
    public function isDirect(): bool
    {
        return $this->pid === null
            && $this->subpid === null
            && $this->channel === null
            && $this->utmSource === null
            && $this->utmMedium === null
            && $this->utmCampaign === null
            && $this->utmContent === null
            && $this->utmTerm === null
            && $this->gclid === null
            && $this->fbclid === null;
    }

    private static function normalize(string $field, mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        // 제어문자를 지운다. 로그 인젝션과 헤더 오염을 막는다.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw) ?? '';
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (in_array($field, self::LOWERCASED, true)) {
            $value = mb_strtolower($value, 'UTF-8');
        }

        $limit = self::LIMITS[$field];

        return mb_substr($value, 0, $limit, 'UTF-8');
    }
}
