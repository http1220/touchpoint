<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 요청 상관 ID.
 *
 * OpenResty 가 $request_id 를 만들어 FastCGI 파라미터로 넘긴다.
 * 앱은 그 값을 그대로 이어받아 로그·dispatch_log·응답 헤더에 같은 값을 단다.
 * 엣지 로그 한 줄에서 워커의 전송 시도까지 한 번에 따라갈 수 있게 하는 것이 목적이다.
 *
 * 엣지 값을 그대로 믿지 않는 이유:
 * 이 값은 결국 요청 경로로 들어온다. 로그에 그대로 찍히므로,
 * 개행이나 제어문자가 섞이면 로그 한 줄이 두 줄로 쪼개진다(로그 인젝션).
 */
final class TraceId
{
    /** nginx 의 $request_id 는 32자 hex 다. 그 형태만 통과시킨다. */
    private const PATTERN = '/\A[0-9a-f]{32}\z/';

    private function __construct()
    {
    }

    /**
     * 엣지가 준 값을 검증해 쓰고, 없거나 형태가 다르면 새로 만든다.
     *
     * 형태가 다르다고 요청을 거절하지는 않는다. 상관 ID 때문에
     * 수집이 실패하면 본말이 전도된다 — 조용히 새 값을 발급한다.
     */
    public static function fromEdge(?string $raw): string
    {
        $raw = strtolower(trim((string) $raw));

        return preg_match(self::PATTERN, $raw) === 1 ? $raw : self::generate();
    }

    /** 엣지가 없는 경로(CLI 워커·테스트)에서 쓴다. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
