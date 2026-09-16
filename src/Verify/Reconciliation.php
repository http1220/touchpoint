<?php

declare(strict_types=1);

namespace App\Verify;

use DateTimeImmutable;

/**
 * 보낸 것과 매체가 집계한 것을 맞댄다.
 *
 * 순수 함수다. 네트워크도 DB 도 모르고, 목록만 받는다 → ADR-017
 * 대조 규칙이 헷갈리는 부분이라(특히 `duplicated`) 테스트로 못박는다.
 *
 * 다섯 가지가 나온다.
 *
 *   matched     보냈고 집계됐다
 *   pending     보냈는데 아직 안 보인다 — **매체의 처리 창 안이다**
 *   missing     보냈는데 **처리 창이 지나도** 집계되지 않았다 ← 이 클래스의 존재 이유
 *   unexpected  안 보냈는데 매체에 있다 (다른 경로·다른 환경에서 들어온 것)
 *   duplicated  집계는 됐는데 **한 건을 두 번 이상** 받았다
 *
 * ── pending 이 생긴 이유 (09-16) ──
 *
 * 처음에는 안 보이면 전부 missing 이었다. 그래서 GA4 반영률을 +25시간에 95.2%
 * 로 보고 **"4.8% 영구 유실"** 이라고 문서와 지표 화면에 적었다. +40시간에
 * 100% 였다 → docs/benchmarks.md 5-1. 은행 사고에서도 "사라진 게 아니라 지연"
 * 을 고객이 구분하지 못한 일이 반복됐다 → docs/incidents/payment-incidents.md 11·15
 *
 * 그래서 보낸 시각과 처리 창을 받으면 **창 안에서 안 보이는 것은 누락이라고
 * 부르지 않는다.** 시각을 넘기지 않으면 예전처럼 전부 missing 이다.
 */
final class Reconciliation
{
    /** GA4 표준 보고서의 처리 지연 상한으로 문서에 적힌 값 */
    public const GA4_WINDOW_SECONDS = 48 * 3600;

    /**
     * @param list<string>       $matched
     * @param list<string>       $pending
     * @param list<string>       $missing
     * @param list<string>       $unexpected
     * @param array<string,int>  $duplicated
     */
    private function __construct(
        public readonly array $matched,
        public readonly array $missing,
        public readonly array $unexpected,
        public readonly array $duplicated,
        public readonly int $sentCount,
        public readonly int $observedCount,
        public readonly array $pending = [],
    ) {
    }

    /**
     * @param list<string>                     $sent     우리가 보낸 transaction_id
     * @param array<string,int>                $observed 매체가 돌려준 transaction_id => 건수
     * @param array<string,DateTimeImmutable>  $sentAt   transaction_id => 보낸 시각. 없으면 창을 적용하지 않는다
     * @param DateTimeImmutable|null           $now      판정 시각
     * @param int                              $windowSeconds 매체의 처리 창
     */
    public static function of(
        array $sent,
        array $observed,
        array $sentAt = [],
        ?DateTimeImmutable $now = null,
        int $windowSeconds = 0,
    ): self {
        // 같은 id 를 두 번 보냈을 수도 있다. 대조는 집합으로 한다.
        $sentUnique = array_values(array_unique($sent));

        $matched = [];
        $missing = [];
        $pending = [];
        $duplicated = [];

        foreach ($sentUnique as $id) {
            if (!isset($observed[$id])) {
                if (self::withinWindow($sentAt[$id] ?? null, $now, $windowSeconds)) {
                    $pending[] = $id;
                } else {
                    $missing[] = $id;
                }

                continue;
            }

            $matched[] = $id;

            if ($observed[$id] > 1) {
                $duplicated[$id] = $observed[$id];
            }
        }

        $unexpected = array_values(array_diff(array_keys($observed), $sentUnique));

        sort($missing);
        sort($pending);
        sort($unexpected);

        return new self(
            $matched,
            $missing,
            $unexpected,
            $duplicated,
            count($sentUnique),
            count($observed),
            $pending,
        );
    }

    /** 집계된 비율. pending 도 분모에 있다 — "지금 보이는 것" 이다. */
    public function reflectionRate(): float
    {
        if ($this->sentCount === 0) {
            return 0.0;
        }

        return count($this->matched) / $this->sentCount;
    }

    /** 누락·중복이 없다. **pending 은 문제가 아니다** — 아직 판정할 때가 아니다. */
    public function isClean(): bool
    {
        return $this->missing === [] && $this->duplicated === [];
    }

    /** 판정이 끝났는가. pending 이 남아 있으면 반영률은 최종값이 아니다. */
    public function isSettled(): bool
    {
        return $this->pending === [];
    }

    private static function withinWindow(?DateTimeImmutable $sentAt, ?DateTimeImmutable $now, int $windowSeconds): bool
    {
        if ($sentAt === null || $now === null || $windowSeconds <= 0) {
            return false;
        }

        return $now->getTimestamp() - $sentAt->getTimestamp() < $windowSeconds;
    }
}
