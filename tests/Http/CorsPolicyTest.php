<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\CorsPolicy;
use PHPUnit\Framework\TestCase;

final class CorsPolicyTest extends TestCase
{
    private const SHOP = 'sshwan.com';
    private const LP = 'https://lp.sshwan.com';

    private function policy(bool $wildcard = false): CorsPolicy
    {
        return CorsPolicy::forSite(self::SHOP, $wildcard);
    }

    // ── 본 요청 ─────────────────────────────────────────────

    public function test_허용된_오리진은_그대로_반향한다(): void
    {
        $d = $this->policy()->actual(self::LP);

        self::assertTrue($d->allowed);
        // * 가 아니라 정확한 오리진이어야 한다. 쿠키를 실으려면 그래야 한다.
        self::assertSame(self::LP, $d->headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $d->headers['Access-Control-Allow-Credentials']);
    }

    public function test_오리진을_반향하면_Vary_Origin_이_반드시_붙는다(): void
    {
        // 빠뜨리면 중간 캐시가 A 오리진 응답을 B 오리진에게 준다.
        // 허용 목록이 있어도 캐시가 그걸 무효로 만든다.
        foreach (['https://lp.sshwan.com', 'https://app.sshwan.com', 'https://m.sshwan.com'] as $origin) {
            $d = $this->policy()->actual($origin);

            self::assertSame('Origin', $d->headers['Vary'], "오리진: {$origin}");
        }
    }

    public function test_모르는_오리진은_거부하고_CORS_헤더를_붙이지_않는다(): void
    {
        $d = $this->policy()->actual('https://evil.example');

        self::assertFalse($d->allowed);
        self::assertSame(403, $d->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $d->headers);
        // 거부하는 응답에도 Vary 는 붙인다. 이 응답 역시 오리진에 따라 달라진다.
        self::assertSame('Origin', $d->headers['Vary']);
    }

    /**
     * 허용 목록을 문자열 포함으로 검사하면 뚫리는 값들.
     *
     * @dataProvider 닮은_오리진
     */
    public function test_비슷해_보이는_오리진을_통과시키지_않는다(string $origin): void
    {
        self::assertFalse($this->policy()->actual($origin)->allowed, $origin);
    }

    public static function 닮은_오리진(): array
    {
        return [
            '접미사 위조'   => ['https://lp.sshwan.com.evil.example'],
            '접두 위조'     => ['https://evil-lp.sshwan.com'],
            '서브의 서브'   => ['https://a.lp.sshwan.com'],
            'http 다운그레이드' => ['http://lp.sshwan.com'],
            '포트 추가'     => ['https://lp.sshwan.com:8443'],
            '등록 안 된 호스트' => ['https://api2.sshwan.com'],
        ];
    }

    public function test_대소문자와_끝_슬래시는_정규화한다(): void
    {
        foreach (['HTTPS://LP.SSHWAN.COM', 'https://lp.sshwan.com/', '  https://lp.sshwan.com  '] as $raw) {
            self::assertTrue($this->policy()->actual($raw)->allowed, $raw);
        }
    }

    public function test_Origin_이_없으면_크로스오리진이_아니므로_통과시킨다(): void
    {
        // 서버 간 호출이나 curl. CORS 가 막을 대상이 아니다.
        $d = $this->policy()->actual(null);

        self::assertTrue($d->allowed);
        self::assertSame([], $d->headers);
    }

    public function test_null_오리진은_허용하지_않는다(): void
    {
        // 샌드박스 iframe 등이 리터럴 "null" 을 보낸다. 출처를 특정할 수 없다.
        $d = $this->policy()->actual('null');

        self::assertTrue($d->allowed);       // Origin 없음과 같게 취급
        self::assertSame([], $d->headers);   // 그러나 CORS 를 허용해 주지는 않는다
    }

    // ── preflight ───────────────────────────────────────────

    public function test_preflight_는_204_와_메서드_헤더_최대수명을_준다(): void
    {
        $d = $this->policy()->preflight(self::LP, 'POST');

        self::assertTrue($d->allowed);
        self::assertSame(204, $d->status);
        self::assertSame('POST, OPTIONS', $d->headers['Access-Control-Allow-Methods']);
        self::assertSame('Content-Type', $d->headers['Access-Control-Allow-Headers']);
        self::assertSame((string) CorsPolicy::MAX_AGE, $d->headers['Access-Control-Max-Age']);
        self::assertSame('Origin', $d->headers['Vary']);
    }

    public function test_preflight_가_요청_헤더_목록을_그대로_반향하지_않는다(): void
    {
        // 반향하면 임의 헤더가 통과해 정책이 사실상 없는 것과 같아진다.
        $d = $this->policy()->preflight(self::LP, 'POST');

        self::assertStringNotContainsString('x-', strtolower($d->headers['Access-Control-Allow-Headers']));
    }

    public function test_허용하지_않는_메서드의_preflight_는_거부한다(): void
    {
        $d = $this->policy()->preflight(self::LP, 'DELETE');

        self::assertFalse($d->allowed);
        self::assertSame(403, $d->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $d->headers);
    }

    public function test_Origin_없는_OPTIONS_는_preflight_가_아니다(): void
    {
        $d = $this->policy()->preflight(null, 'POST');

        self::assertFalse($d->allowed);
        self::assertSame(405, $d->status);
    }

    // ── 실험 스위치 ─────────────────────────────────────────

    public function test_와일드카드_스위치는_일부러_깨진_조합을_낸다(): void
    {
        $d = $this->policy(true)->actual(self::LP);

        // 브라우저가 거부하는 조합이다. 거부당하는 것이 이 스위치의 목적이다.
        self::assertSame('*', $d->headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $d->headers['Access-Control-Allow-Credentials']);
        self::assertTrue($d->allowed, '서버는 200 을 준다 — 막는 쪽은 브라우저다');
    }

    public function test_와일드카드여도_허용_목록은_여전히_검사한다(): void
    {
        // 스위치는 헤더만 망가뜨린다. 서버의 오리진 검사까지 끄지는 않는다.
        self::assertFalse($this->policy(true)->actual('https://evil.example')->allowed);
    }

    // ── 구성 ────────────────────────────────────────────────

    public function test_수집기_자신은_허용_목록에_없다(): void
    {
        // 같은 오리진 요청에는 CORS 가 걸리지 않는다. 넣을 이유가 없다.
        self::assertFalse($this->policy()->actual('https://api.sshwan.com')->allowed);
    }

    public function test_도메인이_비면_아무것도_허용하지_않는다(): void
    {
        $policy = CorsPolicy::forSite('');

        self::assertFalse($policy->actual('https://lp.sshwan.com')->allowed);
    }
}
