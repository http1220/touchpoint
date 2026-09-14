<?php

declare(strict_types=1);

namespace App\Tests\Channel;

use App\Channel\HttpResponse;
use App\Channel\MetaChannel;
use PHPUnit\Framework\TestCase;

final class MetaChannelTest extends TestCase
{
    private const NOW = 1789205400;

    /** 지금 아웃박스에 실제로 쌓이는 payload 모양 그대로. */
    private function frozen(array $override = []): array
    {
        return array_merge([
            'conversion_uid' => '01J8XKP2M4',
            'type' => 'purchase',
            'value_minor' => 9900,
            'currency' => 'KRW',
            'client_id' => '954472196.1789205372',
            'user_uid' => '01A08C02DB59781D9A4425880D006636',
            'occurred_at_unix' => 1789205372,
        ], $override);
    }

    /** 브라우저 맥락이 실린 payload. 적재 계약을 넓혔을 때의 모양. */
    private function withBrowser(array $override = []): array
    {
        return $this->frozen(array_merge([
            'event_source_url' => 'https://lp.example.com/',
            'client_user_agent' => 'Mozilla/5.0',
            'client_ip_address' => '203.0.113.7',
        ], $override));
    }

    private function channel(FakeHttpClient $http, string $testCode = ''): MetaChannel
    {
        return new MetaChannel($http, '1234567890', 'tok', $testCode, 'v26.0', 3000, null, static fn (): int => self::NOW);
    }

    private function event(FakeHttpClient $http): array
    {
        return $http->lastJson()['data'][0] ?? [];
    }

    // ── ADR-005 검증의 핵심 ─────────────────────────────────

    public function test_지금의_payload_로는_보내지_않고_이유를_남긴다(): void
    {
        $http = new FakeHttpClient(200, '{"events_received":1}');
        $r = $this->channel($http)->send($this->frozen());

        self::assertTrue($r->isDead());
        self::assertSame(0, $http->calls, '거절될 요청을 보내지 않는다');
        self::assertStringContainsString('event_source_url', (string) $r->error);
        self::assertStringContainsString('client_user_agent', (string) $r->error);
    }

    public function test_브라우저_맥락이_있으면_보낸다(): void
    {
        $http = new FakeHttpClient(200, '{"events_received":1,"fbtrace_id":"x"}');
        $r = $this->channel($http)->send($this->withBrowser());

        self::assertTrue($r->isSent());
        self::assertSame('https://graph.facebook.com/v26.0/1234567890/events', $http->lastUrl);
    }

    // ── 매핑 ────────────────────────────────────────────────

    public function test_토큰은_URL_이_아니라_본문에_있다(): void
    {
        $http = new FakeHttpClient(200);
        $this->channel($http)->send($this->withBrowser());

        self::assertStringNotContainsString('tok', (string) $http->lastUrl);
        self::assertSame('tok', $http->lastJson()['access_token']);
    }

    public function test_test_event_code_는_본문_최상위에만_붙는다(): void
    {
        $http = new FakeHttpClient(200);
        $this->channel($http, 'TEST123')->send($this->withBrowser());
        self::assertSame('TEST123', $http->lastJson()['test_event_code']);
        self::assertArrayNotHasKey('test_event_code', $this->event($http));

        $http = new FakeHttpClient(200);
        $this->channel($http)->send($this->withBrowser());
        self::assertArrayNotHasKey('test_event_code', $http->lastJson());
    }

    public function test_external_id_는_정규화_후_SHA256_이고_UA_IP_는_해시하지_않는다(): void
    {
        $http = new FakeHttpClient(200);
        $this->channel($http)->send($this->withBrowser());
        $u = $this->event($http)['user_data'];

        self::assertSame([hash('sha256', '01a08c02db59781d9a4425880d006636')], $u['external_id']);
        self::assertSame('Mozilla/5.0', $u['client_user_agent']);
        self::assertSame('203.0.113.7', $u['client_ip_address']);
        self::assertArrayNotHasKey('fbp', $u, '없는 값은 빈 문자열로도 넣지 않는다');
    }

    public function test_event_id_는_GA4_transaction_id_와_같은_conversion_uid_다(): void
    {
        $http = new FakeHttpClient(200);
        $this->channel($http)->send($this->withBrowser());
        $e = $this->event($http);

        self::assertSame('01J8XKP2M4', $e['event_id']);
        self::assertSame('Purchase', $e['event_name']);
        self::assertSame('website', $e['action_source']);
        self::assertSame(1789205372, $e['event_time'], '초 단위다. GA4 의 마이크로초와 다르다');
    }

    public function test_금액은_통화별_표시_단위다(): void
    {
        $http = new FakeHttpClient(200);
        $this->channel($http)->send($this->withBrowser(['currency' => 'usd', 'value_minor' => 9950]));

        self::assertSame(['currency' => 'USD', 'value' => 99.5], $this->event($http)['custom_data']);
    }

    public function test_7일보다_오래된_전환은_보내지_않는다(): void
    {
        $http = new FakeHttpClient(200);
        $r = $this->channel($http)->send($this->withBrowser(['occurred_at_unix' => self::NOW - 7 * 86400 - 1]));

        self::assertTrue($r->isDead());
        self::assertSame(0, $http->calls);
    }

    // ── 응답 해석 ───────────────────────────────────────────

    public function test_HTTP_400_이어도_레이트리밋_코드면_재시도한다(): void
    {
        $http = new FakeHttpClient(400, '{"error":{"message":"(#17) User request limit reached","code":17}}');
        $r = $this->channel($http)->send($this->withBrowser());

        self::assertTrue($r->shouldRetry(), '상태 코드만 보면 dead 로 버려진다');
    }

    public function test_토큰_오류는_재시도하지_않는다(): void
    {
        $http = new FakeHttpClient(400, '{"error":{"message":"Invalid OAuth access token","type":"OAuthException","code":190}}');
        $r = $this->channel($http)->send($this->withBrowser());

        self::assertTrue($r->isDead());
        self::assertStringContainsString('code=190', (string) $r->error);
    }

    public function test_5xx_와_연결_실패는_재시도한다(): void
    {
        self::assertTrue($this->channel(new FakeHttpClient(503))->send($this->withBrowser())->shouldRetry());
        self::assertTrue($this->channel(new FakeHttpClient(null))->send($this->withBrowser())->shouldRetry());
    }

    public function test_2xx_인데_events_received_가_0_이면_dead(): void
    {
        $http = new FakeHttpClient(200);
        $http->queue[] = new HttpResponse(200, '{"events_received":0}', 5);

        self::assertTrue($this->channel($http)->send($this->withBrowser())->isDead());
    }

    public function test_자격증명이_없으면_보내지_않는다(): void
    {
        $http = new FakeHttpClient(200);
        $r = (new MetaChannel($http, '', 'tok'))->send($this->withBrowser());

        self::assertTrue($r->isDead());
        self::assertSame(0, $http->calls);
    }
}
