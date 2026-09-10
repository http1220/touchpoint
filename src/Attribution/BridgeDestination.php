<?php

declare(strict_types=1);

namespace App\Attribution;

use InvalidArgumentException;

/**
 * 브리지(`/go`)가 보낼 목적지 URL 을 만든다.
 *
 * 유입 파라미터를 목적지까지 어떻게 넘길 것인가에 두 방식이 있고,
 * 이 프로젝트는 둘을 모두 구현해 비교한다. → docs/api-spec.md 1장
 *
 *   VID       `/l/8733?vid=<32자 hex>`      기본값
 *   PASSTHRU  `/l/8733?utm_source=..&gclid=..`  대조군
 *
 * **기본값이 VID 인 이유는 공유 때문이다.**
 * PASSTHRU 로 보내면 사용자가 주소창을 복사해 친구에게 보내는 순간
 * 그 친구의 방문이 원래 사용자의 광고 클릭으로 집계된다. 유입 하나가
 * 여러 건으로 불어나고, 매체 정산이 틀어진다.
 * VID 는 방문 식별자만 넘기므로 그 일이 일어나지 않는다 —
 * 남의 vid 로 들어와도 그건 이미 만들어진 방문 한 건일 뿐이다.
 *
 * 이 클래스는 프레임워크를 모른다. 순수 함수라 테스트가 된다 → ADR-017
 */
final class BridgeDestination
{
    public const MODE_VID = 'vid';
    public const MODE_PASSTHRU = 'passthru';

    /**
     * PASSTHRU 에서 목적지까지 넘기는 파라미터.
     *
     * 화이트리스트다. 들어온 쿼리를 통째로 넘기면 브리지가
     * 임의 파라미터를 실어 나르는 통로가 된다.
     */
    private const FORWARDED = [
        'pid', 'subpid', 'channel',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'gclid', 'fbclid',
    ];

    private function __construct()
    {
    }

    /**
     * @param string               $base     예: https://lp.example.com
     * @param string               $work     작품 ID. 숫자만 허용한다
     * @param string               $visitUid 32자 hex
     * @param array<string, mixed> $query    원 요청의 쿼리스트링
     */
    public static function build(
        string $base,
        string $work,
        string $visitUid,
        array $query,
        string $mode = self::MODE_VID,
    ): string {
        $base = rtrim($base, '/');
        $path = $base.'/l/'.self::work($work);

        if ($mode === self::MODE_PASSTHRU) {
            $forwarded = [];
            foreach (self::FORWARDED as $key) {
                $value = $query[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $forwarded[$key] = $value;
                }
            }

            // 넘길 게 하나도 없으면 물음표만 남은 URL 을 만들지 않는다.
            return $forwarded === [] ? $path : $path.'?'.http_build_query($forwarded);
        }

        return $path.'?vid='.self::visitUid($visitUid);
    }

    /** 모르는 값이 오면 기본값으로 떨어진다. 브리지가 400 을 내는 것보다 낫다. */
    public static function normalizeMode(?string $raw): string
    {
        return strtolower(trim((string) $raw)) === self::MODE_PASSTHRU
            ? self::MODE_PASSTHRU
            : self::MODE_VID;
    }

    /**
     * 작품 ID 는 숫자만.
     *
     * 이 값이 Location 헤더로 나간다. 검증하지 않으면 개행을 넣어
     * 헤더를 쪼개거나(응답 분할), 다른 사이트로 보내는 오픈 리다이렉트가 된다.
     */
    private static function work(string $raw): string
    {
        if (preg_match('/\A[0-9]{1,18}\z/', $raw) !== 1) {
            throw new InvalidArgumentException('작품 ID 가 올바르지 않습니다.');
        }

        return $raw;
    }

    private static function visitUid(string $raw): string
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $raw) !== 1) {
            throw new InvalidArgumentException('방문 식별자가 올바르지 않습니다.');
        }

        return $raw;
    }
}
