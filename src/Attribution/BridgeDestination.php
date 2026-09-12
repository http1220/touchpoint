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
 * **기본값이 VID 인 이유는 공유 때문이다.** 재 봤다 → docs/failure-scenarios.md C-2
 *
 * PASSTHRU 로 보내면 사용자가 주소창을 복사해 친구에게 보내는 순간
 * 그 친구의 방문이 원래 사용자의 광고 클릭으로 집계된다. 실제로 클릭 한 번에
 * 방문이 둘 생겼다. 유입이 불어나고 매체 정산이 틀어진다.
 *
 * VID 는 그 일이 일어나지 않는다 — 링크를 받은 사람은 새 방문이 되지 않고
 * 기존 방문 하나에 흡수된다. **다만 공짜는 아니다.** 흡수된다는 건
 * 그 사람이 원래 방문자의 쿠키를 물려받는다는 뜻이고, 이후 그 사람의
 * 활동은 전부 남의 방문 기록이 된다. 둘 다 틀리고, 틀리는 방향이 다르다 —
 * PASSTHRU 는 건수를, VID 는 사람을 틀린다.
 *
 * 정산이 걸린 쪽이 건수라 VID 를 기본값으로 둔다. 그리고 VID 가 넘기는 값에는
 * 권한도 개인정보도 잔액도 붙어 있지 않아서, 주워 써도 할 수 있는 일이
 * "이미 있는 방문에 접점을 갱신한다" 로 끝난다. 세션 식별자였다면
 * 이 선택은 성립하지 않는다.
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
