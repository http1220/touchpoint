<?php

declare(strict_types=1);

namespace App\Channel;

use RuntimeException;

/**
 * 운영용 HTTP 클라이언트.
 *
 * 워커에서만 쓴다. 웹 요청 처리 중에는 외부 HTTP 를 부르지 않는다 —
 * 그게 아웃박스를 둔 이유다 → docs/outbox-and-channels.md 3장
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * 연결 타임아웃을 따로 둔다.
     *
     * 전체 타임아웃만 두면 상대가 연결을 받아 주고 응답을 안 주는 경우와
     * 아예 연결이 안 되는 경우를 구분하지 못한다. 후자는 즉시 포기하는 편이
     * 낫다 — 워커 슬롯을 3초 동안 잡고 있을 이유가 없다.
     */
    private const CONNECT_TIMEOUT_MS = 1000;

    public function __construct(
        private readonly string $userAgent = 'touchpoint-worker/1.0',
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl 확장이 없습니다.');
        }
    }

    public function postForm(string $url, array $fields, int $timeoutMs = 3000): HttpResponse
    {
        return $this->post(
            $url,
            http_build_query($fields),
            ['Content-Type: application/x-www-form-urlencoded'],
            $timeoutMs
        );
    }

    public function postJson(string $url, string $json, array $headers = [], int $timeoutMs = 3000): HttpResponse
    {
        $merged = ['Content-Type: application/json'];

        foreach ($headers as $name => $value) {
            $merged[] = $name.': '.$value;
        }

        return $this->post($url, $json, $merged, $timeoutMs);
    }

    /**
     * @param list<string> $headers 이미 `Name: value` 로 조립된 것
     */
    private function post(string $url, string $body, array $headers, int $timeoutMs): HttpResponse
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_USERAGENT => $this->userAgent,

            // 리다이렉트를 따라가지 않는다. 매체 API 가 리다이렉트를 준다면
            // 그건 우리가 잘못된 주소로 보내고 있다는 뜻이다.
            CURLOPT_FOLLOWLOCATION => false,

            // 인증서 검증을 끄지 않는다. 끄면 중간자가 전환 데이터를 본다.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $startedAt = microtime(true);
        $body = curl_exec($ch);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($body === false) {
            $error = curl_error($ch) ?: 'curl 오류';
            curl_close($ch);

            // 응답을 못 받았다. 요청이 닿았는지조차 모른다.
            return HttpResponse::failure($error, $elapsedMs);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($status, (string) $body, $elapsedMs);
    }
}
