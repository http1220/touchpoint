<?php

declare(strict_types=1);

namespace App\Tests\Attribution;

use App\Attribution\Touchpoint;
use App\Attribution\TouchpointResolver;
use App\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TouchpointResolverTest extends TestCase
{
    private TouchpointResolver $resolver;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->resolver = new TouchpointResolver();
        $this->clock = new FrozenClock();
    }

    #[Test]
    public function 소스가_있고_first가_없으면_first와_last를_모두_만든다(): void
    {
        $incoming = $this->touch(['pid' => 'google', 'utm_source' => 'google']);

        $result = $this->resolver->resolve(null, null, $incoming);

        self::assertSame($incoming, $result->createFirst);
        self::assertSame($incoming, $result->upsertLast);
    }

    #[Test]
    public function 소스가_있고_first가_이미_있으면_first는_보존하고_last만_갱신한다(): void
    {
        $first = $this->touch(['pid' => 'google']);
        $incoming = $this->touch(['pid' => 'meta']);

        $result = $this->resolver->resolve($first, $first, $incoming);

        self::assertNull($result->createFirst, 'first-touch 는 덮어쓰지 않는다');
        self::assertSame($incoming, $result->upsertLast);
    }

    #[Test]
    public function 직접유입은_기존_last를_덮어쓰지_않는다(): void
    {
        // 광고로 들어왔던 방문자가 북마크로 재방문한 상황.
        // 여기서 last 를 direct 로 덮으면 광고 성과가 조용히 사라진다.
        $adTouch = $this->touch(['pid' => 'google', 'gclid' => 'EAIaIQ']);
        $direct = $this->touch([]);

        $result = $this->resolver->resolve($adTouch, $adTouch, $direct);

        self::assertNull($result->createFirst);
        self::assertNull($result->upsertLast);
        self::assertFalse($result->changesAnything());
    }

    #[Test]
    public function 직접유입이면서_last도_없으면_last만_기록한다(): void
    {
        $direct = $this->touch([]);

        $result = $this->resolver->resolve(null, null, $direct);

        self::assertNull($result->createFirst, '직접 유입은 first-touch 가 되지 않는다');
        self::assertSame($direct, $result->upsertLast, '방문 자체는 남겨야 한다');
    }

    #[Test]
    public function gclid만_있어도_소스가_있는_접점으로_본다(): void
    {
        // Google Ads 자동 태그는 utm 없이 gclid 만 붙인다.
        $incoming = $this->touch(['gclid' => 'EAIaIQobChMI']);

        self::assertFalse($incoming->isDirect());
        self::assertNotNull($this->resolver->resolve(null, null, $incoming)->createFirst);
    }

    #[Test]
    public function 분류축은_소문자로_정규화된다(): void
    {
        $touch = $this->touch([
            'pid' => '  Google  ',
            'channel' => 'SEARCH',
            'utm_source' => 'NAVER',
            'utm_medium' => 'CPC',
            'utm_campaign' => 'Fall_Sale_2026',
            'gclid' => 'EAIaIQobChMI',
        ]);

        self::assertSame('google', $touch->pid);
        self::assertSame('search', $touch->channel);
        self::assertSame('naver', $touch->utmSource);
        self::assertSame('cpc', $touch->utmMedium);

        // 자유 텍스트와 매체 발급 식별자는 원문을 보존한다
        self::assertSame('Fall_Sale_2026', $touch->utmCampaign);
        self::assertSame('EAIaIQobChMI', $touch->gclid);
    }

    #[Test]
    public function 빈값과_공백만_있는_값은_null로_처리된다(): void
    {
        $touch = $this->touch(['pid' => '', 'channel' => '   ', 'utm_source' => null]);

        self::assertNull($touch->pid);
        self::assertNull($touch->channel);
        self::assertNull($touch->utmSource);
        self::assertTrue($touch->isDirect());
    }

    #[Test]
    public function 제어문자는_제거된다(): void
    {
        $touch = $this->touch(['pid' => "goo\x00gle\n"]);

        self::assertSame('google', $touch->pid);
    }

    #[Test]
    public function 컬럼_길이를_넘으면_잘린다(): void
    {
        // 파라미터 하나가 길다고 insert 가 실패하면 유입 자체를 잃는다
        $touch = $this->touch(['pid' => str_repeat('a', 200)]);

        self::assertNotNull($touch->pid);
        self::assertSame(64, mb_strlen($touch->pid));
    }

    /** @param array<string, mixed> $query */
    private function touch(array $query): Touchpoint
    {
        return Touchpoint::fromQuery($query, $this->clock->now());
    }
}
