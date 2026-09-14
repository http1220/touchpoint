<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * 매체 하나로 전환을 보내는 계약.
 *
 * 신규 매체를 붙여도 **워커는 바뀌지 않는다** — 구현체 둘(GA4·Meta)로
 * 확인했다. "구현 하나 + `.env` 한 줄" 이라던 처음 주장은 실측 6파일이었다
 * → [ADR-005 「검증」](../../docs/decisions/ADR-005-channel-adapter.md)
 *
 * 이 계약이 격리하지 못하는 것: 매체가 요구하는 값을 **적재 시점에 이미
 * 버렸다면** `send()` 안에서 되살릴 수 없다. payload 는 적재할 때 얼려진다.
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
