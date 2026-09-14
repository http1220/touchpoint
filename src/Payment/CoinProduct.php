<?php

declare(strict_types=1);

namespace App\Payment;

use DateInterval;
use DateTimeImmutable;

/**
 * 서버 상품표. 코드 → 코인 수 · 금액 · 통화.
 *
 * **금액의 진실은 서버에 있다.** `/purchase` 가 본문의 amount_minor 를
 * 그대로 믿으면 9900원짜리를 100원에 살 수 있다 — 클라이언트가 금액을
 * 정하는 것은 결제 조작이지 입력 오류가 아니다. 본문의 금액은
 * `matches()` 로 **대조만** 하고, 어긋나면 422 다 → 계획 1장
 *
 * 스텁 PG 라 실제 청구는 없지만 표는 진짜처럼 둔다. 여기를 대충 두면
 * 대조라는 절차 자체가 흉내가 된다.
 */
final class CoinProduct
{
    /** 유료 코인의 유효기간. 관측된 약관은 유료 5년 · 무료 1년 → ADR-006 */
    public const PAID_EXPIRY = 'P5Y';

    /**
     * code => [코인 수, 금액(minor unit), 통화]
     *
     * **금액은 통화 안에서 유일해야 한다.** payments 스키마에 상품 코드를
     * 넣을 칸이 없어서(20260909000400, 변경 금지) `captured` 시점에
     * 금액으로 상품을 역인덱스한다 — findByPrice(). 금액이 겹치는 상품을
     * 여기 추가하면 **적립되는 코인 수가 조용히 달라진다.**
     * CoinProductTest 가 그 순간 깨지게 해 뒀다.
     *
     * @var array<string, array{0: int, 1: int, 2: string}>
     */
    private const TABLE = [
        'coin_30' => [30, 3300, 'KRW'],
        'coin_100' => [100, 9900, 'KRW'],
        'coin_300' => [300, 28000, 'KRW'],
    ];

    private function __construct(
        public readonly string $code,
        public readonly int $coins,
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
    }

    public static function find(mixed $code): ?self
    {
        if (!is_string($code) || !isset(self::TABLE[$code])) {
            return null;
        }

        [$coins, $amount, $currency] = self::TABLE[$code];

        return new self($code, $coins, $amount, $currency);
    }

    /**
     * 금액으로 상품을 되찾는다.
     *
     * `captured` 웹훅에는 상품 코드가 없고 payments 행에도 없다. 코인을
     * 몇 개 줄지는 그때 결정해야 하므로, 우리가 직접 기록한 금액에서
     * 역으로 찾는다. 웹훅 본문의 금액이 아니라 **payments 의 금액**이다.
     */
    public static function findByPrice(int $amountMinor, string $currency): ?self
    {
        $currency = strtoupper(trim($currency));

        foreach (self::TABLE as $code => [$coins, $amount, $cur]) {
            if ($amount === $amountMinor && $cur === $currency) {
                return new self($code, $coins, $amount, $cur);
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::TABLE);
    }

    /**
     * 본문이 들고 온 금액이 상품표와 같은가.
     *
     * 빠뜨린 것도 어긋난 것으로 본다. 금액을 안 보내면 서버 값으로
     * 채워 주는 편의를 두면, 그 경로로는 대조가 아예 일어나지 않는다.
     */
    public function matches(?int $amountMinor, ?string $currency): bool
    {
        return $amountMinor === $this->amountMinor
            && $currency !== null
            && strtoupper($currency) === $this->currency;
    }

    public function expiresAt(DateTimeImmutable $grantedAt): DateTimeImmutable
    {
        return $grantedAt->add(new DateInterval(self::PAID_EXPIRY));
    }
}
