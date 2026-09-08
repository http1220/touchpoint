<?php

declare(strict_types=1);

namespace App\Tests\Dispatch;

use App\Dispatch\BackoffPolicy;
use App\Support\FrozenClock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackoffPolicyTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = (new FrozenClock('2026-09-08T00:00:00+00:00'))->now();
    }

    /** 지터를 0으로 고정해 간격 자체를 검증한다. */
    private function withoutJitter(): BackoffPolicy
    {
        return new BackoffPolicy(jitterRatio: 0.0);
    }

    #[Test]
    #[DataProvider('간격표')]
    public function 시도_횟수마다_정해진_간격을_돌려준다(int $attempts, int $expectedSeconds): void
    {
        $next = $this->withoutJitter()->nextRetryAt($attempts, $this->now);

        self::assertNotNull($next);
        self::assertSame($expectedSeconds, $next->getTimestamp() - $this->now->getTimestamp());
    }

    /** @return array<string, array{int, int}> */
    public static function 간격표(): array
    {
        return [
            '1회 실패 → 30초' => [1, 30],
            '2회 실패 → 2분' => [2, 120],
            '3회 실패 → 10분' => [3, 600],
            '4회 실패 → 1시간' => [4, 3600],
            '5회 실패 → 3시간' => [5, 10800],
        ];
    }

    #[Test]
    public function 최대_시도를_넘으면_포기한다(): void
    {
        $policy = $this->withoutJitter();

        self::assertNotNull($policy->nextRetryAt(BackoffPolicy::MAX_ATTEMPTS, $this->now));
        self::assertNull(
            $policy->nextRetryAt(BackoffPolicy::MAX_ATTEMPTS + 1, $this->now),
            '더 보내지 않고 dead 로 넘긴다',
        );
    }

    #[Test]
    public function 지터는_기준값의_지정_범위_안에_머문다(): void
    {
        // 난수를 최소·최대로 고정해 경계를 확인한다
        $lowest = new BackoffPolicy(0.2, static fn (float $min, float $max): float => $min);
        $highest = new BackoffPolicy(0.2, static fn (float $min, float $max): float => $max);

        $base = 120;   // 2회 실패 기준값

        $low = $lowest->nextRetryAt(2, $this->now)->getTimestamp() - $this->now->getTimestamp();
        $high = $highest->nextRetryAt(2, $this->now)->getTimestamp() - $this->now->getTimestamp();

        self::assertSame((int) round($base * 0.8), $low);
        self::assertSame((int) round($base * 1.2), $high);
    }

    #[Test]
    public function 지터가_커도_즉시_재시도로_무너지지_않는다(): void
    {
        $policy = new BackoffPolicy(0.99, static fn (float $min, float $max): float => $min);

        $next = $policy->nextRetryAt(1, $this->now);

        self::assertNotNull($next);
        self::assertGreaterThanOrEqual(1, $next->getTimestamp() - $this->now->getTimestamp());
    }

    #[Test]
    #[DataProvider('재시도_판정표')]
    public function 응답에_따라_재시도_여부를_정한다(?int $status, bool $expected, string $why): void
    {
        self::assertSame($expected, $this->withoutJitter()->shouldRetry($status), $why);
    }

    /** @return array<string, array{?int, bool, string}> */
    public static function 재시도_판정표(): array
    {
        return [
            '네트워크 오류·타임아웃' => [null, true, '일시적일 수 있다'],
            '408 요청 타임아웃' => [408, true, '시간이 해결한다'],
            '429 레이트리밋' => [429, true, '기다렸다 다시 보낸다'],
            '500 서버 오류' => [500, true, '매체 쪽 문제'],
            '503 서비스 불가' => [503, true, '매체 쪽 문제'],
            '400 잘못된 요청' => [400, false, '페이로드를 고쳐야 한다. 다섯 번 더 보낼 이유가 없다'],
            '401 인증 실패' => [401, false, '자격증명을 고쳐야 한다'],
            '404 없음' => [404, false, '엔드포인트가 틀렸다'],
        ];
    }

    #[Test]
    public function 시도_횟수가_0이하면_거부한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->withoutJitter()->nextRetryAt(0, $this->now);
    }

    #[Test]
    public function 지터_비율이_범위를_벗어나면_거부한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BackoffPolicy(1.0);
    }
}
