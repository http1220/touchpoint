<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * HTTP 응답 한 건.
 *
 * `status` 가 null 이면 응답을 못 받은 것이다 — 연결 실패·타임아웃·DNS.
 * 이것과 "5xx 를 받았다" 는 다르다. 전자는 요청이 닿았는지도 모르고,
 * 후자는 닿았는데 상대가 처리하지 못한 것이다.
 * 재시도 판정은 둘 다 "재시도" 지만, 중복 위험은 전자가 더 크다.
 */
final class HttpResponse
{
    public function __construct(
        public readonly ?int $status,
        public readonly string $body = '',
        public readonly int $elapsedMs = 0,
        public readonly ?string $error = null,
    ) {
    }

    public static function failure(string $error, int $elapsedMs = 0): self
    {
        return new self(null, '', $elapsedMs, $error);
    }

    public function isSuccess(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * 본문을 JSON 으로. 파싱 실패는 빈 배열로 돌려준다.
     *
     * 매체가 HTML 오류 페이지를 주는 경우가 있어서 예외를 던지지 않는다 —
     * 전송 결과 판정이 파싱 실패로 무너지면 안 된다.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $parsed = json_decode($this->body, true);

        return is_array($parsed) ? $parsed : [];
    }
}
