<?php

declare(strict_types=1);

namespace App\Http;

/**
 * CORS 판정 결과.
 *
 * 헤더를 실제로 내보내는 일은 호출자(컨트롤러)가 한다.
 * 여기서는 "무엇을 내보내야 하는가"만 담는다 — 그래야 프레임워크 없이 검증된다.
 */
final class CorsDecision
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $reason,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function allow(int $status, array $headers, string $reason): self
    {
        return new self(true, $status, $headers, $reason);
    }

    /** @param array<string, string> $headers */
    public static function deny(int $status, array $headers, string $reason): self
    {
        return new self(false, $status, $headers, $reason);
    }
}
