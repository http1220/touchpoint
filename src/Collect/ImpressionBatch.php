<?php

declare(strict_types=1);

namespace App\Collect;

/**
 * `POST /impression` 본문 — 한 페이지에서 본 배너 N개를 **한 요청으로**.
 *
 * 노출은 배너마다 요청을 보내면 페이지 하나에 수십 요청이 된다. 그래서
 * 모아서 한 번에 보내고, 대개 이탈할 때 sendBeacon 으로 나간다. 그러면
 * **응답을 읽을 수 없고 재시도도 없다** — 여기서의 판단 기준이 그것이다.
 *
 *   배치 전체를 422 로 거절하지 않는다   → 틀린 항목만 버리고 나머지를 받는다
 *   너무 많으면 앞의 MAX 개만 받는다      → 나눠 보내라고 해도 들을 쪽이 없다
 *   같은 (작품, 슬롯) 이 두 번이면 한 번  → 관찰자 중복 등록은 클라이언트 버그다
 *
 * 전체가 형식부터 틀렸을 때(items 가 없거나 배열이 아님)만 invalid 다.
 */
final class ImpressionBatch
{
    public const MAX_ITEMS = 50;

    /**
     * @param list<array{work_id: int, slot: string}> $items
     */
    private function __construct(
        public readonly array $items,
        public readonly int $dropped,
        public readonly ?string $reason,
    ) {
    }

    public function isValid(): bool
    {
        return $this->reason === null;
    }

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $raw = $body['items'] ?? null;

        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            return new self([], 0, 'items 는 비어 있지 않은 배열이어야 합니다.');
        }

        $items = [];
        $seen = [];
        $dropped = 0;

        foreach ($raw as $i => $item) {
            if ($i >= self::MAX_ITEMS) {
                $dropped += count($raw) - self::MAX_ITEMS;
                break;
            }

            $workId = is_array($item) ? self::workId($item['work_id'] ?? null) : null;
            $slot = is_array($item) ? Slot::normalize($item['slot'] ?? null) : null;

            if ($workId === null || $slot === null) {
                $dropped++;
                continue;
            }

            $key = $workId.'|'.$slot;

            if (isset($seen[$key])) {
                $dropped++;
                continue;
            }

            $seen[$key] = true;
            $items[] = ['work_id' => $workId, 'slot' => $slot];
        }

        return new self($items, $dropped, null);
    }

    private static function workId(mixed $raw): ?int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/\A[1-9]\d{0,17}\z/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }
}
