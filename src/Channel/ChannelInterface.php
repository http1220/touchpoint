<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * 매체 하나로 전환을 보내는 계약.
 *
 * 신규 매체를 붙이는 일이 **이 인터페이스 구현 하나 + `.env` 한 줄**로
 * 끝나야 한다는 것이 [ADR-005](../../docs/decisions/ADR-005-channel-adapter.md) 의 주장이고,
 * 구현체를 둘 이상 만들어야 그 주장이 검증된다.
 *
 * 워커는 이 인터페이스만 안다. 어느 매체인지, HTTP 를 쓰는지도 모른다.
 */
interface ChannelInterface
{
    /**
     * 매체 코드. `dispatch_outbox.channel` 에 들어가는 값이다.
     *
     * 이 값으로 아웃박스 행과 어댑터가 이어지므로, 한 번 정하면
     * 바꿀 때 이미 쌓인 행을 같이 옮겨야 한다.
     */
    public function name(): string;

    /**
     * 전환 하나를 매체 규격으로 바꿔 보낸다.
     *
     * 예외를 던지지 않는다. 전송 실패는 예외적인 일이 아니라
     * **정상적으로 일어나는 일**이고, 워커는 그걸 상태로 다뤄야 한다.
     *
     * @param array<string, mixed> $conversion 전환 payload (dispatch_outbox.payload)
     */
    public function send(array $conversion): DispatchResult;
}
