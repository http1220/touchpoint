<?php

declare(strict_types=1);

namespace App\Coin;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 코인 소진 계획을 세운다.
 *
 * 순수 함수다. DB 도 시각도 주입받으므로 프레임워크 없이 테스트된다.
 *
 * 소진 순서 — 만료 임박 순, 동률이면 무료 먼저
 *
 *   만료로 사라지는 것이 사용자에게 가장 큰 손실이다. 그래서 1차 기준은 만료일이다.
 *   같은 날 만료라면 무료를 먼저 쓴다. 유료 코인은 환불 대상이 될 수 있어
 *   남겨 두는 편이 분쟁이 적다.
 *
 *   유료 5년 / 무료 1년이라는 규칙 때문에 대개는 무료가 자연히 먼저 만료되지만,
 *   이벤트로 받은 유료 성격 코인이나 만료가 겹치는 경우가 있어 2차 기준이 필요하다.
 */
final class LedgerService
{
    /**
     * @param Lot[] $lots 이 사용자의 지급 건 전부. 순서는 상관없다
     *
     * @return SpendPlan[] 차감 계획. 순서대로 적용한다
     *
     * @throws InsufficientCoinException 사용 가능 잔액이 모자랄 때. 부분 차감은 하지 않는다
     */
    public function plan(array $lots, int $amount, DateTimeImmutable $now): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('차감 수량은 1 이상이어야 합니다.');
        }

        $usable = array_values(array_filter(
            $lots,
            static fn (Lot $lot): bool => $lot->isUsableAt($now),
        ));

        $available = array_sum(array_map(static fn (Lot $lot): int => $lot->remaining, $usable));

        if ($available < $amount) {
            throw new InsufficientCoinException($amount, $available);
        }

        usort($usable, static function (Lot $a, Lot $b): int {
            // 1차: 만료 임박 순
            $byExpiry = $a->expiresAt <=> $b->expiresAt;
            if ($byExpiry !== 0) {
                return $byExpiry;
            }

            // 2차: 무료 먼저
            if ($a->isFree() !== $b->isFree()) {
                return $a->isFree() ? -1 : 1;
            }

            // 3차: id 순. 같은 조건이면 결과가 항상 같아야 테스트가 성립한다
            return $a->id <=> $b->id;
        });

        $plans = [];
        $remaining = $amount;

        foreach ($usable as $lot) {
            if ($remaining === 0) {
                break;
            }

            $take = min($lot->remaining, $remaining);
            $plans[] = new SpendPlan($lot->id, $take);
            $remaining -= $take;
        }

        return $plans;
    }

    /**
     * 지금 쓸 수 있는 잔액.
     *
     * 저장된 숫자가 아니라 계산 결과다. 만료 배치가 실패해도 잔액은 틀리지 않는다.
     *
     * @param Lot[] $lots
     */
    public function balance(array $lots, DateTimeImmutable $now): int
    {
        $total = 0;

        foreach ($lots as $lot) {
            if ($lot->isUsableAt($now)) {
                $total += $lot->remaining;
            }
        }

        return $total;
    }
}
