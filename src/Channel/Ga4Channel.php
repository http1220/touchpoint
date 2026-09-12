<?php

declare(strict_types=1);

namespace App\Channel;

use App\Dispatch\BackoffPolicy;

/**
 * GA4 Measurement Protocol 어댑터.
 *
 * 규격과 실측은 docs/outbox-and-channels.md 7장에 있다. 여기서 반복하지
 * 않되, **코드가 그 문서 없이도 읽히도록** 판단이 들어간 자리에만 이유를 남긴다.
 *
 * 이 어댑터가 다루는 함정이 둘이다.
 *
 *   ① 운영 엔드포인트는 페이로드가 틀려도 204 를 준다.
 *      HTTP 상태만 보고 성공으로 넘기면 틀린 페이로드가 조용히 버려진다.
 *
 *   ② 검증 엔드포인트는 api_secret 을 확인하지 않는다.
 *      validationMessages 가 비어 있어도 자격 증명이 맞다는 뜻이 아니다.
 *      그래서 "검증 통과 = 집계됨" 이라고 쓰지 않는다.
 */
final class Ga4Channel implements ChannelInterface
{
    public const NAME = 'ga4';

    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    /** 언더스코어가 붙지 않는다. `/_debug_/` 는 404 다 — 2026-09-12 실측. */
    private const DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

    /** 문서 확인값. 넘으면 GA4 가 조용히 자르거나 이벤트를 버린다. */
    private const MAX_EVENTS = 25;
    private const MAX_PARAMS = 25;
    private const MAX_NAME_LEN = 40;
    private const MAX_VALUE_LEN = 100;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $measurementId,
        private readonly string $apiSecret,
        private readonly bool $debug = false,
        private readonly int $timeoutMs = 3000,
        private readonly ?BackoffPolicy $backoff = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function send(array $conversion): DispatchResult
    {
        if ($this->measurementId === '' || $this->apiSecret === '') {
            // 설정이 비었다. 재시도해도 채워지지 않는다.
            return DispatchResult::dead(null, 0, 'GA4 측정 ID 또는 API secret 이 비어 있습니다.');
        }

        $payload = $this->payload($conversion);
        $invalid = $this->lint($payload);

        if ($invalid !== null) {
            // 보내기 전에 우리가 먼저 잡는다. GA4 는 알려주지 않기 때문이다.
            return DispatchResult::dead(null, 0, $invalid);
        }

        $res = $this->http->postJson(
            $this->endpoint(),
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [],
            $this->timeoutMs
        );

        return $this->interpret($res);
    }

    public function endpoint(): string
    {
        $base = $this->debug ? self::DEBUG_ENDPOINT : self::ENDPOINT;

        return $base.'?'.http_build_query([
            'measurement_id' => $this->measurementId,
            'api_secret' => $this->apiSecret,
        ]);
    }

    // ────────────────────────────────────────────────────────

    /**
     * 우리 전환 모델 → GA4 페이로드.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function payload(array $c): array
    {
        $params = [
            'transaction_id' => (string) ($c['conversion_uid'] ?? ''),

            // 실시간 보고서에 잡히려면 있어야 한다. 서버 전송에는 실제
            // 참여 시간이라는 개념이 없으므로 최소값을 넣는다.
            'engagement_time_msec' => 1,
        ];

        if (isset($c['currency'], $c['value_minor'])) {
            $params['currency'] = strtoupper((string) $c['currency']);
            $params['value'] = $this->majorUnits((string) $c['currency'], (int) $c['value_minor']);
        }

        if (isset($c['session_id'])) {
            $params['session_id'] = (string) $c['session_id'];
        }

        $payload = [
            'client_id' => (string) ($c['client_id'] ?? ''),
            'non_personalized_ads' => false,
            'events' => [[
                'name' => $this->eventName((string) ($c['type'] ?? '')),
                'params' => $params,
            ]],
        ];

        if (isset($c['user_uid']) && $c['user_uid'] !== '') {
            $payload['user_id'] = (string) $c['user_uid'];
        }

        if (isset($c['occurred_at_unix'])) {
            // 마이크로초다. 밀리초를 넣으면 1970년으로 간다.
            $payload['timestamp_micros'] = (int) $c['occurred_at_unix'] * 1000000;
        }

        return $payload;
    }

    /**
     * 우리 전환 타입 → GA4 이벤트 이름.
     *
     * GA4 의 추천 이벤트 이름을 쓴다. 자체 이름을 쓰면 표준 보고서에
     * 잡히지 않고 탐색 보고서를 직접 만들어야 한다.
     */
    private function eventName(string $type): string
    {
        return match ($type) {
            'purchase' => 'purchase',
            'signup' => 'sign_up',
            'subscribe' => 'purchase',
            default => 'custom_conversion',
        };
    }

    /**
     * 정수 minor unit → GA4 가 기대하는 표시 단위.
     *
     * ISO 4217 의 소수 자릿수가 통화마다 다르다. KRW·JPY 는 0 자리라
     * 그대로지만 USD 는 100 으로 나눠야 한다. **이 변환을 한 곳에 모은다** —
     * 흩어지면 통화 하나를 추가할 때마다 빠뜨린 곳이 생긴다.
     */
    private function majorUnits(string $currency, int $minor): int|float
    {
        $exponent = match (strtoupper($currency)) {
            'KRW', 'JPY', 'VND', 'CLP' => 0,
            'BHD', 'KWD', 'OMR', 'TND' => 3,
            default => 2,
        };

        return $exponent === 0 ? $minor : $minor / (10 ** $exponent);
    }

    /**
     * 보내기 전 자체 검사.
     *
     * GA4 는 한도를 넘겨도 오류를 주지 않고 조용히 자르거나 버린다.
     * 그러면 "보냈는데 없다" 가 되고 원인을 찾을 단서가 남지 않는다.
     * 우리가 먼저 잡아서 `dead` 로 보내면 최소한 행에 이유가 남는다.
     *
     * @param array<string, mixed> $p
     */
    private function lint(array $p): ?string
    {
        if (($p['client_id'] ?? '') === '') {
            return 'client_id 가 비어 있습니다. _ga 쿠키를 못 읽었습니다.';
        }

        $events = $p['events'] ?? [];

        if ($events === []) {
            return '이벤트가 없습니다.';
        }

        if (count($events) > self::MAX_EVENTS) {
            return sprintf('이벤트가 %d개입니다. 최대 %d개.', count($events), self::MAX_EVENTS);
        }

        foreach ($events as $i => $e) {
            $name = (string) ($e['name'] ?? '');

            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $name) !== 1) {
                return sprintf('이벤트[%d] 이름이 올바르지 않습니다: [%s]. 영문자로 시작해야 합니다.', $i, $name);
            }

            if (mb_strlen($name) > self::MAX_NAME_LEN) {
                return sprintf('이벤트[%d] 이름이 %d자입니다. 최대 %d자.', $i, mb_strlen($name), self::MAX_NAME_LEN);
            }

            $params = $e['params'] ?? [];

            if (count($params) > self::MAX_PARAMS) {
                return sprintf('이벤트[%d] 파라미터가 %d개입니다. 최대 %d개.', $i, count($params), self::MAX_PARAMS);
            }

            foreach ($params as $k => $v) {
                if (is_string($v) && mb_strlen($v) > self::MAX_VALUE_LEN) {
                    return sprintf('이벤트[%d] 파라미터 [%s] 값이 %d자입니다. 최대 %d자.', $i, $k, mb_strlen($v), self::MAX_VALUE_LEN);
                }
            }
        }

        return null;
    }

    /**
     * 응답 해석.
     *
     * 검증 모드에서는 HTTP 상태가 아니라 `validationMessages` 를 본다 —
     * 그게 이 모드를 쓰는 유일한 이유다.
     */
    private function interpret(HttpResponse $res): DispatchResult
    {
        if (!$res->isSuccess()) {
            $error = $res->error ?? ('HTTP '.($res->status ?? '없음'));
            $backoff = $this->backoff ?? new BackoffPolicy();

            return $backoff->shouldRetry($res->status)
                ? DispatchResult::retry($res->status, $res->elapsedMs, $error)
                : DispatchResult::dead($res->status, $res->elapsedMs, $error);
        }

        if ($this->debug) {
            $messages = $res->json()['validationMessages'] ?? [];

            if ($messages !== []) {
                // 형식이 틀렸다. 같은 페이로드를 다시 보내도 같은 답이다.
                return DispatchResult::dead(
                    $res->status,
                    $res->elapsedMs,
                    'GA4 검증 실패: '.json_encode($messages, JSON_UNESCAPED_UNICODE)
                );
            }
        }

        /*
         * 여기서 sent 로 넘기지만, 이것이 "집계됐다" 는 뜻은 아니다.
         *
         *   운영 엔드포인트  틀린 페이로드에도 204 를 준다
         *   검증 엔드포인트  api_secret 을 확인하지 않는다
         *
         * 둘 다 2026-09-12 에 실제로 눌러 확인했다. 집계 여부는
         * GA4 실시간 보고서로만 알 수 있고, 그건 코드가 할 수 없는 일이다.
         */
        return DispatchResult::sent($res->status, $res->elapsedMs);
    }
}
