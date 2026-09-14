<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 웹훅 서명 판정 결과.
 *
 * CorsDecision 과 같은 모양이다 — 통과와 거절을 한 타입에 담고, 응답을
 * 만드는 일은 호출자가 한다. 예외로 던지지 않는 이유도 같다: 위조 서명은
 * **정상적으로 일어나는 결과**지 예외 상황이 아니다.
 *
 * `reason` 은 로그용이다. **응답 본문에 그대로 실으면 안 된다** —
 * "시각 창 초과" 와 "서명 불일치" 를 구분해 주면 공격자에게 재생 창의
 * 크기를 알려 준다. 밖으로 나가는 것은 401 invalid-signature 하나다.
 */
final class SignatureVerdict
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reason,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function reject(string $reason): self
    {
        return new self(false, $reason);
    }
}
