<?php

declare(strict_types=1);

namespace App\Tests\Coin;

use App\Coin\InsufficientCoinException;
use App\Coin\LedgerService;
use App\Coin\Lot;
use App\Support\FrozenClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LedgerServiceTest extends TestCase
{
    private LedgerService $ledger;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->ledger = new LedgerService();
        $this->now = (new FrozenClock('2026-09-08T00:00:00+00:00'))->now();
    }

    #[Test]
    public function 만료가_임박한_것부터_쓴다(): void
    {
        $lots = [
            $this->lot(id: 1, kind: Lot::KIND_PAID, remaining: 100, expires: '+5 years'),
            $this->lot(id: 2, kind: Lot::KIND_PAID, remaining: 100, expires: '+30 days'),
        ];

        $plans = $this->ledger->plan($lots, 50, $this->now);

        self::assertCount(1, $plans);
        self::assertSame(2, $plans[0]->lotId, '먼저 사라질 코인부터 쓴다');
        self::assertSame(50, $plans[0]->amount);
    }

    #[Test]
    public function 만료일이_같으면_무료를_먼저_쓴다(): void
    {
        // 유료는 환불 대상이 될 수 있어 남겨 두는 편이 분쟁이 적다
        $lots = [
            $this->lot(id: 1, kind: Lot::KIND_PAID, remaining: 100, expires: '+1 year'),
            $this->lot(id: 2, kind: Lot::KIND_FREE, remaining: 100, expires: '+1 year'),
        ];

        $plans = $this->ledger->plan($lots, 10, $this->now);

        self::assertSame(2, $plans[0]->lotId);
    }

    #[Test]
    public function 한_건으로_모자라면_여러_건에_걸쳐_차감한다(): void
    {
        $lots = [
            $this->lot(id: 1, kind: Lot::KIND_FREE, remaining: 30, expires: '+10 days'),
            $this->lot(id: 2, kind: Lot::KIND_PAID, remaining: 50, expires: '+20 days'),
            $this->lot(id: 3, kind: Lot::KIND_PAID, remaining: 90, expires: '+5 years'),
        ];

        $plans = $this->ledger->plan($lots, 100, $this->now);

        self::assertCount(3, $plans);
        self::assertSame([1, 30], [$plans[0]->lotId, $plans[0]->amount]);
        self::assertSame([2, 50], [$plans[1]->lotId, $plans[1]->amount]);
        self::assertSame([3, 20], [$plans[2]->lotId, $plans[2]->amount]);
        self::assertSame(100, array_sum(array_map(static fn ($p) => $p->amount, $plans)));
    }

    #[Test]
    public function 만료된_코인은_쓰지_않는다(): void
    {
        $lots = [
            $this->lot(id: 1, kind: Lot::KIND_FREE, remaining: 500, expires: '-1 day'),
            $this->lot(id: 2, kind: Lot::KIND_PAID, remaining: 10, expires: '+1 year'),
        ];

        $plans = $this->ledger->plan($lots, 10, $this->now);

        self::assertCount(1, $plans);
        self::assertSame(2, $plans[0]->lotId);
    }

    #[Test]
    public function 잔여가_0인_건은_건너뛴다(): void
    {
        $lots = [
            $this->lot(id: 1, kind: Lot::KIND_FREE, remaining: 0, expires: '+10 days'),
            $this->lot(id: 2, kind: Lot::KIND_PAID, remaining: 5, expires: '+1 year'),
        ];

        $plans = $this->ledger->plan($lots, 5, $this->now);

        self::assertCount(1, $plans);
        self::assertSame(2, $plans[0]->lotId);
    }

    #[Test]
    public function 잔액이_모자라면_부분_차감하지_않고_거부한다(): void
    {
        $lots = [$this->lot(id: 1, kind: Lot::KIND_PAID, remaining: 5, expires: '+1 year')];

        try {
            $this->ledger->plan($lots, 10, $this->now);
            self::fail('InsufficientCoinException 이 발생해야 한다');
        } catch (InsufficientCoinException $e) {
            self::assertSame(10, $e->requested);
            self::assertSame(5, $e->available);
        }
    }

    #[Test]
    public function 만료된_코인은_잔액에도_잡히지_않는다(): void
    {
        $lots = [
            $this->lot(id: 1, kind: Lot::KIND_FREE, remaining: 500, expires: '-1 second'),
            $this->lot(id: 2, kind: Lot::KIND_PAID, remaining: 20, expires: '+1 year'),
        ];

        self::assertSame(20, $this->ledger->balance($lots, $this->now));
    }

    #[Test]
    public function 만료_경계에서는_아직_쓸_수_있다(): void
    {
        // expires_at 이 정확히 현재 시각이면 만료로 본다. 경계를 명시해 둔다.
        $justExpired = new Lot(1, Lot::KIND_PAID, 10, $this->now);
        $stillAlive = new Lot(2, Lot::KIND_PAID, 10, $this->now->modify('+1 second'));

        self::assertFalse($justExpired->isUsableAt($this->now));
        self::assertTrue($stillAlive->isUsableAt($this->now));
    }

    #[Test]
    public function 차감_수량이_0이하면_거부한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->ledger->plan([], 0, $this->now);
    }

    #[Test]
    public function 알_수_없는_종류는_거부한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Lot(1, 'bonus', 10, $this->now->modify('+1 year'));
    }

    private function lot(int $id, string $kind, int $remaining, string $expires): Lot
    {
        return new Lot(
            $id,
            $kind,
            $remaining,
            (new DateTimeImmutable('2026-09-08T00:00:00+00:00', new DateTimeZone('UTC')))->modify($expires),
        );
    }
}
