<?php

declare(strict_types=1);

namespace App\Tests\Channel;

use App\Channel\DispatchResult;
use App\Channel\Ga4Channel;
use PHPUnit\Framework\TestCase;

final class Ga4ChannelTest extends TestCase
{
    private const OK_DEBUG = '{"validationMessages":[]}';

    /** @return array<string, mixed> */
    private function conversion(array $override = []): array
    {
        return array_merge([
            'conversion_uid' => '01J8XKP2M4',
            'type' => 'purchase',
            'value_minor' => 9900,
            'currency' => 'KRW',
            'client_id' => '954472196.1789205372',
            'user_uid' => '01a08c02db59781d9a4425880d006636',
            'occurred_at_unix' => 1789205372,
        ], $override);
    }

    // ── 엔드포인트 ──────────────────────────────────────────

    public function test_검증_모드는_debug_경로로_보낸다(): void
    {
        $http = new FakeHttpClient(200, self::OK_DEBUG);
        (new Ga4Channel($http, 'G-TEST', 'secret', TRUE))->send($this->conversion());

        self::assertStringContainsString('/debug/mp/collect', (string) $http->lastUrl);
        // 언더스코어가 붙은 경로는 404 다 — 2026-09-12 실측
        self::assertStringNotContainsString('/_debug_/', (string) $http->lastUrl);
    }

    public function test_운영_모드는_mp_collect_로_보낸다(): void
    {
        $http = new FakeHttpClient(204);
        (new Ga4Channel($http, 'G-TEST', 'secret'))->send($this->conversion());

        self::assertStringContainsString('/mp/collect', (string) $http->lastUrl);
        self::assertStringNotContainsString('/debug/', (string) $http->lastUrl);
    }

    public function test_자격증명이_쿼리스트링에_붙는다(): void
    {
        $http = new FakeHttpClient(204);
        (new Ga4Channel($http, 'G-TEST', 's3cr3t'))->send($this->conversion());

        self::assertStringContainsString('measurement_id=G-TEST', (string) $http->lastUrl);
        self::assertStringContainsString('api_secret=s3cr3t', (string) $http->lastUrl);
    }

    // ── 페이로드 매핑 ───────────────────────────────────────

    public function test_전환_타입을_GA4_추천_이벤트_이름으로_바꾼다(): void
    {
        foreach (['purchase' => 'purchase', 'signup' => 'sign_up', '알수없음' => 'custom_conversion'] as $type => $expected) {
            $http = new FakeHttpClient(204);
            (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion(['type' => $type]));

            self::assertSame($expected, $http->lastJson()['events'][0]['name'], "type={$type}");
        }
    }

    public function test_conversion_uid_가_transaction_id_로_간다(): void
    {
        // 픽셀과 서버가 같은 전환을 보낼 때 GA4 가 합치는 기준이다.
        $http = new FakeHttpClient(204);
        (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion());

        self::assertSame('01J8XKP2M4', $http->lastParams()['transaction_id']);
    }

    public function test_timestamp_는_마이크로초다(): void
    {
        $http = new FakeHttpClient(204);
        (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion(['occurred_at_unix' => 1789205372]));

        // 밀리초를 넣으면 1970년으로 간다.
        self::assertSame(1789205372000000, $http->lastJson()['timestamp_micros']);
    }

    public function test_실시간_보고서용_파라미터가_들어간다(): void
    {
        $http = new FakeHttpClient(204);
        (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion());

        self::assertSame(1, $http->lastParams()['engagement_time_msec']);
    }

    /** @dataProvider 통화 */
    public function test_통화별_소수_자릿수를_반영한다(string $currency, int $minor, int|float $expected): void
    {
        $http = new FakeHttpClient(204);
        (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion([
            'currency' => $currency,
            'value_minor' => $minor,
        ]));

        self::assertSame($expected, $http->lastParams()['value'], $currency);
        self::assertSame(strtoupper($currency), $http->lastParams()['currency']);
    }

    public static function 통화(): array
    {
        return [
            'KRW 는 0자리'  => ['KRW', 9900, 9900],
            'JPY 는 0자리'  => ['jpy', 1200, 1200],
            'USD 는 2자리'  => ['USD', 9900, 99.0],
            'USD 나머지 있음' => ['USD', 9950, 99.5],
            'KWD 는 3자리'  => ['KWD', 9900, 9.9],
        ];
    }

    public function test_금액이_없으면_통화도_넣지_않는다(): void
    {
        $http = new FakeHttpClient(204);
        $c = $this->conversion();
        unset($c['value_minor'], $c['currency']);

        (new Ga4Channel($http, 'G-T', 's'))->send($c);

        self::assertArrayNotHasKey('value', $http->lastParams());
        self::assertArrayNotHasKey('currency', $http->lastParams());
    }

    // ── 보내기 전 자체 검사 ─────────────────────────────────

    public function test_client_id_가_없으면_보내지_않고_dead(): void
    {
        $http = new FakeHttpClient(204);
        $r = (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion(['client_id' => '']));

        self::assertTrue($r->isDead());
        self::assertSame(0, $http->calls, '보내기 전에 잡아야 한다');
        self::assertStringContainsString('client_id', (string) $r->error);
    }

    public function test_설정이_비면_보내지_않고_dead(): void
    {
        $http = new FakeHttpClient(204);
        $r = (new Ga4Channel($http, '', ''))->send($this->conversion());

        self::assertTrue($r->isDead());
        self::assertSame(0, $http->calls);
    }

    public function test_이벤트_이름_한도를_우리가_먼저_잡는다(): void
    {
        // GA4 는 한도를 넘겨도 오류를 주지 않고 조용히 버린다.
        $http = new FakeHttpClient(204);
        $r = (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion([
            'type' => str_repeat('가', 50),   // custom_conversion 으로 매핑되므로 통과해야 한다
        ]));

        self::assertTrue($r->isSent(), '모르는 타입은 custom_conversion 으로 정규화된다');
    }

    // ── 응답 해석 ───────────────────────────────────────────

    public function test_검증_메시지가_있으면_dead(): void
    {
        $body = '{"validationMessages":[{"fieldPath":"events","description":"invalid","validationCode":"NAME_INVALID"}]}';
        $http = new FakeHttpClient(200, $body);

        $r = (new Ga4Channel($http, 'G-T', 's', TRUE))->send($this->conversion());

        // 같은 페이로드를 다시 보내도 같은 답이다. 재시도할 이유가 없다.
        self::assertTrue($r->isDead());
        self::assertStringContainsString('NAME_INVALID', (string) $r->error);
    }

    public function test_검증_메시지가_비면_sent(): void
    {
        $http = new FakeHttpClient(200, self::OK_DEBUG);
        $r = (new Ga4Channel($http, 'G-T', 's', TRUE))->send($this->conversion());

        self::assertTrue($r->isSent());
    }

    public function test_운영_모드는_본문을_보지_않는다(): void
    {
        // 운영 엔드포인트는 틀린 페이로드에도 204 를 주고 본문이 비어 있다.
        // 그 상태에서 검증 메시지를 찾으려 하면 안 된다.
        $http = new FakeHttpClient(204, '');
        $r = (new Ga4Channel($http, 'G-T', 's', FALSE))->send($this->conversion());

        self::assertTrue($r->isSent());
    }

    /** @dataProvider 실패응답 */
    public function test_상태코드에_따라_재시도와_포기를_가른다(?int $status, string $expected): void
    {
        $http = new FakeHttpClient($status, '', '연결 실패');
        $r = (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion());

        self::assertSame($expected, $r->outcome, '상태: '.var_export($status, TRUE));
    }

    public static function 실패응답(): array
    {
        return [
            '500 매체 장애'   => [500, DispatchResult::RETRY],
            '503 일시 중단'   => [503, DispatchResult::RETRY],
            '429 레이트리밋'  => [429, DispatchResult::RETRY],
            '408 타임아웃'    => [408, DispatchResult::RETRY],
            '연결 실패'       => [null, DispatchResult::RETRY],

            // 4xx 를 재시도하면 잘못된 요청을 다섯 번 더 보낸다.
            '400 잘못된 요청' => [400, DispatchResult::DEAD],
            '401 인증 실패'   => [401, DispatchResult::DEAD],
            '404 잘못된 주소' => [404, DispatchResult::DEAD],
        ];
    }

    public function test_소요_시간을_결과에_담는다(): void
    {
        // dispatch_log 의 send_ms 가 된다. 구간을 나눠야 어디가 느린지 안다.
        $http = new FakeHttpClient(204);
        $r = (new Ga4Channel($http, 'G-T', 's'))->send($this->conversion());

        self::assertSame(12, $r->elapsedMs);
    }

    public function test_채널_이름은_아웃박스_값과_같다(): void
    {
        self::assertSame('ga4', (new Ga4Channel(new FakeHttpClient(), 'G-T', 's'))->name());
    }
}
