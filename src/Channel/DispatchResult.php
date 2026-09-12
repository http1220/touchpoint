<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * 전송 한 번의 결과.
 *
 * 상태가 셋인 것이 중요하다. 성공/실패 둘로 나누면 **다시 보낼 가치가 있는
 * 실패**와 **보내 봐야 같은 답이 오는 실패**를 구분하지 못한다.
 * 후자를 재시도하면 잘못된 페이로드를 다섯 번 더 보내게 된다.
 *
 *   SENT    끝. 아웃박스 행을 sent 로
 *   RETRY   백오프를 걸고 다시 (BackoffPolicy 가 언제를 정한다)
 *   DEAD    포기. 사람이 봐야 하는 상태
 */
final class DispatchResult
{
    public const SENT = 'sent';
    public const RETRY = 'retry';
    public const DEAD = 'dead';

    private function __construct(
        public readonly string $outcome,
        public readonly ?int $httpStatus,
        public readonly int $elapsedMs,
        public readonly ?string $error,
    ) {
    }

    public static function sent(?int $httpStatus, int $elapsedMs): self
    {
        return new self(self::SENT, $httpStatus, $elapsedMs, null);
    }

    public static function retry(?int $httpStatus, int $elapsedMs, string $error): self
    {
        return new self(self::RETRY, $httpStatus, $elapsedMs, $error);
    }

    /** 다시 보내도 같은 답이 오는 실패. 페이로드나 설정을 고쳐야 한다. */
    public static function dead(?int $httpStatus, int $elapsedMs, string $error): self
    {
        return new self(self::DEAD, $httpStatus, $elapsedMs, $error);
    }

    public function isSent(): bool
    {
        return $this->outcome === self::SENT;
    }

    public function shouldRetry(): bool
    {
        return $this->outcome === self::RETRY;
    }

    public function isDead(): bool
    {
        return $this->outcome === self::DEAD;
    }
}
