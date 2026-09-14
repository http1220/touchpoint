<?php

declare(strict_types=1);

namespace App\Channel;

use App\Dispatch\BackoffPolicy;

/**
 * Meta Conversions API 어댑터.
 *
 * ADR-005 의 "신규 매체 = 어댑터 클래스 1개 + 설정 1줄" 을 **검증하려고**
 * 붙인 두 번째 실매체다. 결과는 그 ADR 의 「검증」 절에 있다. 여기에는
 * 코드가 GA4 와 다르게 판단해야 했던 자리만 남긴다.
 *
 *   ① **레이트리밋이 429 가 아니다.** Graph API 는 HTTP 400 에 오류 코드
 *      (4·17·32·613·80004)를 싣는다. 공통 BackoffPolicy 는 상태 코드만
 *      보므로 그대로 쓰면 재시도할 것을 dead 로 버린다
 *
 *   ② **배치 하나가 틀리면 배치 전체를 거절한다.** 7일보다 오래된 event_time
 *      하나가 끼면 나머지도 처리되지 않는다. 그래서 보내기 전에 막는다
 *
 *   ③ **웹 이벤트는 브라우저 맥락이 필수다.** action_source=website 는
 *      event_source_url 과 client_user_agent(해시 금지)를 요구한다.
 *      우리 아웃박스 payload 에는 둘 다 없다 → 어댑터가 채울 수 없는 것은
 *      어댑터 문제가 아니라 **적재 계약** 문제다. 지어내지 않고 dead 로 남긴다
 */
final class MetaChannel implements ChannelInterface
{
    public const NAME = 'meta';

    private const HOST = 'https://graph.facebook.com';

    /** 문서 확인값. 넘으면 요청 전체가 거절된다. */
    private const MAX_AGE_SEC = 7 * 86400;

    /** HTTP 400 으로 오지만 시간이 해결하는 오류 코드. */
    private const THROTTLE_CODES = [4, 17, 32, 613, 80004];

    /** 토큰 만료·권한 없음. 재시도해도 같은 답이다. */
    private const AUTH_CODES = [10, 102, 190, 200];

    /**
     * @param string        $testEventCode 비우면 보내지 않는다. 값이 있으면 Events Manager
     *                                     의 테스트 이벤트 탭에 나타난다
     * @param \Closure|null $clock         fn(): int — 7일 창 판정용. 테스트에서 고정한다
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $pixelId,
        private readonly string $accessToken,
        private readonly string $testEventCode = '',
        private readonly string $apiVersion = 'v26.0',
        private readonly int $timeoutMs = 3000,
        private readonly ?BackoffPolicy $backoff = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function send(array $conversion): DispatchResult
    {
        if ($this->pixelId === '' || $this->accessToken === '') {
            return DispatchResult::dead(null, 0, 'Meta 픽셀 ID 또는 액세스 토큰이 비어 있습니다.');
        }

        $event = $this->event($conversion);
        $invalid = $this->lint($event);

        if ($invalid !== null) {
            return DispatchResult::dead(null, 0, $invalid);
        }

        $body = ['data' => [$event]];

        if ($this->testEventCode !== '') {
            // 이벤트 안이 아니라 본문 최상위다.
            $body['test_event_code'] = $this->testEventCode;
        }

        /*
         * 토큰은 URL 이 아니라 본문에 싣는다. Graph API 는 둘 다 받는데,
         * URL 에 두면 프록시·접근 로그·오류 메시지에 남는다.
         * (GA4 는 api_secret 을 쿼리로만 받아서 그 선택지가 없었다.)
         */
        $body['access_token'] = $this->accessToken;

        $res = $this->http->postJson(
            $this->endpoint(),
            (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [],
            $this->timeoutMs
        );

        return $this->interpret($res);
    }

    public function endpoint(): string
    {
        return self::HOST.'/'.$this->apiVersion.'/'.rawurlencode($this->pixelId).'/events';
    }

    // ────────────────────────────────────────────────────────

    /**
     * 우리 전환 모델 → CAPI 서버 이벤트 하나.
     *
     * payload 에 없는 키는 **넣지 않는다.** 빈 문자열을 넣으면 Meta 는
     * "값이 있는데 틀렸다" 로 본다.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function event(array $c): array
    {
        $userData = [];

        if (isset($c['user_uid']) && $c['user_uid'] !== '') {
            // 해시 권장. 정규화(소문자·trim) 후 SHA-256 — 픽셀 쪽과 같은 규칙이어야 맞춰진다.
            $userData['external_id'] = [hash('sha256', strtolower(trim((string) $c['user_uid'])))];
        }

        // 아래 넷은 **해시하면 안 된다.** 원문 그대로 보낸다.
        foreach (['client_user_agent', 'client_ip_address', 'fbp', 'fbc'] as $key) {
            if (isset($c[$key]) && $c[$key] !== '') {
                $userData[$key] = (string) $c[$key];
            }
        }

        $event = [
            'event_name' => $this->eventName((string) ($c['type'] ?? '')),
            'event_time' => (int) ($c['occurred_at_unix'] ?? 0),

            // 픽셀이 같은 전환을 보내면 (event_name, event_id) 로 합쳐진다.
            // GA4 의 transaction_id 와 같은 값이다 — 매체마다 키가 다르면 대조가 끊긴다.
            'event_id' => (string) ($c['conversion_uid'] ?? ''),
            'action_source' => 'website',
            'user_data' => $userData,
        ];

        if (isset($c['event_source_url']) && $c['event_source_url'] !== '') {
            $event['event_source_url'] = (string) $c['event_source_url'];
        }

        if (isset($c['currency'], $c['value_minor'])) {
            $event['custom_data'] = [
                'currency' => strtoupper((string) $c['currency']),
                'value' => MinorUnits::toMajor((string) $c['currency'], (int) $c['value_minor']),
            ];
        }

        return $event;
    }

    /**
     * 우리 전환 타입 → Meta 표준 이벤트 이름.
     *
     * GA4 는 snake_case(`sign_up`), Meta 는 PascalCase(`CompleteRegistration`)다.
     * 같은 전환이 매체마다 다른 이름을 갖는다 — 공통 DTO 를 두지 않은 이유.
     */
    private function eventName(string $type): string
    {
        return match ($type) {
            'purchase' => 'Purchase',
            'signup' => 'CompleteRegistration',
            'subscribe' => 'Subscribe',
            default => 'CustomConversion',
        };
    }

    /**
     * 보내기 전 자체 검사. 거절되면 배치 전체가 날아가므로 GA4 보다 더 중요하다.
     *
     * @param array<string, mixed> $e
     */
    private function lint(array $e): ?string
    {
        if ($e['event_id'] === '') {
            return 'event_id(conversion_uid) 가 비어 있습니다. 픽셀과 중복 제거할 수 없습니다.';
        }

        if ($e['event_time'] <= 0) {
            return 'event_time(occurred_at_unix) 이 없습니다.';
        }

        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        $age = $now - $e['event_time'];

        if ($age > self::MAX_AGE_SEC) {
            // 재시도하면 더 오래될 뿐이다.
            return sprintf('event_time 이 %d초 전입니다. Meta 는 7일(%d초)보다 오래된 이벤트를 거절합니다.',
                $age, self::MAX_AGE_SEC);
        }

        $missing = [];

        if (!isset($e['event_source_url'])) {
            $missing[] = 'event_source_url';
        }

        if (!isset($e['user_data']['client_user_agent'])) {
            $missing[] = 'client_user_agent';
        }

        if ($missing !== []) {
            return 'action_source=website 필수 필드가 payload 에 없습니다: '.implode(', ', $missing)
                .'. 어댑터가 아니라 적재 계약(Conversion_model payload)의 문제입니다.';
        }

        return null;
    }

    /**
     * 응답 해석. 상태 코드만으로 판정하지 않는다 — 머리말 ①.
     */
    private function interpret(HttpResponse $res): DispatchResult
    {
        if ($res->isSuccess()) {
            $received = $res->json()['events_received'] ?? null;

            if (is_int($received) && $received < 1) {
                return DispatchResult::dead($res->status, $res->elapsedMs, 'Meta 가 2xx 를 줬지만 events_received=0 입니다.');
            }

            return DispatchResult::sent($res->status, $res->elapsedMs);
        }

        $error = $res->json()['error'] ?? null;
        $code = is_array($error) && isset($error['code']) ? (int) $error['code'] : null;

        $message = 'HTTP '.($res->status ?? '없음');

        if ($code !== null) {
            $message .= ' code='.$code;
        }

        if (is_array($error) && isset($error['message'])) {
            $message .= ' '.$error['message'];
        } elseif ($res->error !== null) {
            $message .= ' '.$res->error;
        }

        if ($code !== null && in_array($code, self::THROTTLE_CODES, true)) {
            return DispatchResult::retry($res->status, $res->elapsedMs, $message);
        }

        if ($code !== null && in_array($code, self::AUTH_CODES, true)) {
            return DispatchResult::dead($res->status, $res->elapsedMs, $message.' — 자격 증명을 고쳐야 합니다.');
        }

        $backoff = $this->backoff ?? new BackoffPolicy();

        return $backoff->shouldRetry($res->status)
            ? DispatchResult::retry($res->status, $res->elapsedMs, $message)
            : DispatchResult::dead($res->status, $res->elapsedMs, $message);
    }
}
