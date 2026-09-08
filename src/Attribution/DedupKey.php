<?php

declare(strict_types=1);

namespace App\Attribution;

use InvalidArgumentException;

/**
 * 중복 전환을 막는 키.
 *
 * conversions.dedup_key 에 UNIQUE 제약이 걸려 있어, 같은 사건이 두 번 들어오면
 * 두 번째는 DB 가 거부한다. 그래서 이 키는 "같은 사건이면 반드시 같은 값"이어야 한다.
 *
 * 결제 재시도, 웹훅 중복 수신, 클라이언트 재전송이 전부 여기서 걸린다.
 */
final class DedupKey
{
    /** conversions.dedup_key VARCHAR(64) */
    public const MAX_LENGTH = 64;

    private function __construct()
    {
    }

    /**
     * @param string $type       전환 종류. signup | purchase | subscribe
     * @param string $identifier 그 사건을 유일하게 가리키는 값. 결제 UID 등
     */
    public static function for(string $type, string $identifier): string
    {
        $type = trim($type);
        $identifier = trim($identifier);

        if ($type === '') {
            throw new InvalidArgumentException('전환 종류가 비어 있습니다.');
        }

        if ($identifier === '') {
            throw new InvalidArgumentException('식별자가 비어 있습니다.');
        }

        $raw = $type . ':' . $identifier;

        if (strlen($raw) <= self::MAX_LENGTH) {
            return $raw;
        }

        // 길면 해시로 접는다. 앞에 종류를 남겨 사람이 읽을 수 있게 하되,
        // 잘라내지 않고 해시해야 서로 다른 사건이 같은 키가 되지 않는다.
        $prefix = substr($type, 0, 16) . ':';

        return $prefix . substr(hash('sha256', $raw), 0, self::MAX_LENGTH - strlen($prefix));
    }
}
