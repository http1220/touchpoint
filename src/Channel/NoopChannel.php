<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * 아무 데도 보내지 않는 채널.
 *
 * 쓰임이 둘이다.
 *
 *   ① 매체 자격 증명이 없을 때 파이프라인만 검증한다 —
 *      적재 → 선점 → 상태 전이 → 재시도까지는 매체 없이도 돌아야 한다
 *   ② 매체를 잠시 끄고 싶을 때 `.env` 의 CHANNELS 에서 이름만 바꾼다
 *
 * 보낸 것을 기억해 두므로 테스트에서 호출 여부를 확인할 수 있다.
 */
final class NoopChannel implements ChannelInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function __construct(
        private readonly string $name = 'noop',
        private readonly string $outcome = DispatchResult::SENT,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(array $conversion): DispatchResult
    {
        $this->sent[] = $conversion;

        return match ($this->outcome) {
            DispatchResult::RETRY => DispatchResult::retry(503, 0, 'noop: 재시도 흉내'),
            DispatchResult::DEAD => DispatchResult::dead(400, 0, 'noop: 포기 흉내'),
            default => DispatchResult::sent(204, 0),
        };
    }
}
