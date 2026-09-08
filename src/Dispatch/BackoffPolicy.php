<?php

declare(strict_types=1);

namespace App\Dispatch;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 매체 전송 실패 시 언제 다시 시도할 것인가.
 *
 * 대기 간격의 초기값은 아래와 같고, 이 값들은 benchmarks.md 에서 실측으로 조정한다.
 * 지금은 근거 없는 초기값임을 밝혀 둔다 — 값을 먼저 정하고 이유를 붙이지 않기 위해서다.
 *
 *   1회 실패 후  →  30초
 *   2회         →  2분
 *   3회         →  10분
 *   4회         →  1시간
 *   5회         →  3시간
 *   6회         →  포기(dead)
 *
 * 지터를 섞는 이유
 *
 *   매체가 잠시 죽었다 살아나면 실패한 전송이 전부 같은 시각에 재시도된다.
 *   그러면 막 회복한 매체를 다시 밀어 넘어뜨린다. 대기 시간을 조금씩 흩어
 *   이 몰림을 막는다.
 */
final class BackoffPolicy
{
    /** 시도 횟수별 대기 초. 인덱스는 "지금까지 시도한 횟수". */
    private const WAIT_SECONDS = [
        1 => 30,
        2 => 120,
        3 => 600,
        4 => 3600,
        5 => 10800,
    ];

    public const MAX_ATTEMPTS = 5;

    /** @var Closure(float, float): float */
    private Closure $randomizer;

    /**
     * @param float                          $jitterRatio 대기 시간의 ±비율. 0 이면 지터 없음
     * @param (Closure(float, float): float)|null $randomizer  테스트에서 난수를 고정하기 위한 주입점
     */
    public function __construct(
        private readonly float $jitterRatio = 0.2,
        ?Closure $randomizer = null,
    ) {
        if ($jitterRatio < 0.0 || $jitterRatio >= 1.0) {
            throw new InvalidArgumentException('지터 비율은 0 이상 1 미만이어야 합니다.');
        }

        $this->randomizer = $randomizer ?? static function (float $min, float $max): float {
            return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
        };
    }

    /**
     * 다음 재시도 시각. null 이면 포기한다(dead).
     *
     * @param int $attemptsMade 지금까지 시도한 횟수. 첫 시도가 실패했으면 1
     */
    public function nextRetryAt(int $attemptsMade, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($attemptsMade < 1) {
            throw new InvalidArgumentException('시도 횟수는 1 이상이어야 합니다.');
        }

        if (!isset(self::WAIT_SECONDS[$attemptsMade])) {
            return null;
        }

        $base = (float) self::WAIT_SECONDS[$attemptsMade];
        $spread = $base * $this->jitterRatio;
        $wait = $base + ($this->randomizer)(-$spread, $spread);

        // 대기 시간이 음수가 되지 않게. 지터가 커도 즉시 재시도로 무너지면 안 된다.
        $seconds = max(1, (int) round($wait));

        return $now->add(new \DateInterval("PT{$seconds}S"));
    }

    /**
     * 이 응답에 대해 재시도할 가치가 있는가.
     *
     * 4xx 를 재시도하면 잘못된 페이로드를 다섯 번 더 보내게 된다.
     * 고쳐야 할 것은 요청이지 타이밍이 아니다.
     *
     * @param int|null $httpStatus null 이면 네트워크 오류·타임아웃
     */
    public function shouldRetry(?int $httpStatus): bool
    {
        if ($httpStatus === null) {
            return true;   // 연결 실패·타임아웃. 일시적일 수 있다
        }

        if ($httpStatus === 408 || $httpStatus === 429) {
            return true;   // 타임아웃과 레이트리밋은 시간이 해결한다
        }

        if ($httpStatus >= 500) {
            return true;   // 매체 쪽 문제
        }

        return false;      // 2xx 는 성공, 나머지 4xx 는 우리 요청이 잘못됐다
    }
}
