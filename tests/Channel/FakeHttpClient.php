<?php

declare(strict_types=1);

namespace App\Tests\Channel;

use App\Channel\HttpClient;
use App\Channel\HttpResponse;

/**
 * 테스트용 HTTP 클라이언트.
 *
 * **보낸 것을 기억한다.** 어댑터가 하는 일의 대부분이 "우리 모델 →
 * 매체 규격" 변환이므로, 검증할 가치가 있는 것은 응답 처리가 아니라
 * **무엇을 보내려 했는가** 다.
 */
final class FakeHttpClient implements HttpClient
{
    public ?string $lastUrl = null;
    public ?string $lastBody = null;

    /** @var array<string,string> */
    public array $lastHeaders = [];

    public int $calls = 0;

    /** @var array<string,string>|null 마지막 postForm 의 필드 */
    public ?array $lastForm = null;

    /** @var list<HttpResponse> 앞에서부터 하나씩 꺼내 쓴다. 비면 기본 응답 */
    public array $queue = [];

    public function __construct(
        private readonly ?int $status = 204,
        private readonly string $body = '',
        private readonly ?string $error = null,
    ) {
    }

    public function get(string $url, array $headers = [], int $timeoutMs = 3000): HttpResponse
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        return $this->next();
    }

    public function postForm(string $url, array $fields, int $timeoutMs = 3000): HttpResponse
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastForm = $fields;

        return $this->next();
    }

    public function postJson(string $url, string $json, array $headers = [], int $timeoutMs = 3000): HttpResponse
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastBody = $json;
        $this->lastHeaders = $headers;

        return $this->next();
    }

    private function next(): HttpResponse
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        if ($this->status === null) {
            return HttpResponse::failure($this->error ?? '연결 실패', 12);
        }

        return new HttpResponse($this->status, $this->body, 12);
    }

    /** @return array<string, mixed> */
    public function lastJson(): array
    {
        $parsed = json_decode((string) $this->lastBody, true);

        return is_array($parsed) ? $parsed : [];
    }

    /** 첫 이벤트의 params. 가장 자주 보는 자리라 지름길을 둔다. */
    public function lastParams(): array
    {
        return $this->lastJson()['events'][0]['params'] ?? [];
    }
}
