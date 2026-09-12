<?php

declare(strict_types=1);

namespace App\Verify;

/**
 * 보낸 것과 매체가 집계한 것을 맞댄다.
 *
 * 순수 함수다. 네트워크도 DB 도 모르고, 두 목록만 받는다 → ADR-017
 * 대조 규칙이 헷갈리는 부분이라(특히 `duplicated`) 테스트로 못박는다.
 *
 * 네 가지가 나온다.
 *
 *   matched     보냈고 집계됐다
 *   missing     보냈는데 **집계되지 않았다** ← 이게 이 클래스의 존재 이유
 *   unexpected  안 보냈는데 매체에 있다 (다른 경로·다른 환경에서 들어온 것)
 *   duplicated  집계는 됐는데 **한 건을 두 번 이상** 받았다
 *
 * `missing` 이 0 이 아니면 `204` 를 받고도 버려진 전송이 있다는 뜻이고,
 * `duplicated` 가 0 이 아니면 같은 전환이 매체에서 두 번 세어졌다는 뜻이다.
 * 둘 다 전송 로그만 봐서는 절대 보이지 않는다.
 */
final class Reconciliation
{
    /**
     * @param list<string>       $sent     우리가 보낸 transaction_id
     * @param array<string,int>  $observed 매체가 돌려준 transaction_id => 건수
     */
    private function __construct(
        public readonly array $matched,
        public readonly array $missing,
        public readonly array $unexpected,
        public readonly array $duplicated,
        public readonly int $sentCount,
        public readonly int $observedCount,
    ) {
    }

    /**
     * @param list<string>      $sent
     * @param array<string,int> $observed
     */
    public static function of(array $sent, array $observed): self
    {
        // 같은 id 를 두 번 보냈을 수도 있다. 대조는 집합으로 한다.
        $sentUnique = array_values(array_unique($sent));

        $matched = [];
        $missing = [];
        $duplicated = [];

        foreach ($sentUnique as $id) {
            if (!isset($observed[$id])) {
                $missing[] = $id;

                continue;
            }

            $matched[] = $id;

            if ($observed[$id] > 1) {
                $duplicated[$id] = $observed[$id];
            }
        }

        $unexpected = array_values(array_diff(array_keys($observed), $sentUnique));

        sort($missing);
        sort($unexpected);

        return new self(
            $matched,
            $missing,
            $unexpected,
            $duplicated,
            count($sentUnique),
            count($observed),
        );
    }

    /**
     * 반영률. 보낸 것 중 매체가 집계한 비율.
     *
     * **이것이 "전송 성공률" 과 다른 숫자다.** 전송 성공률은 `204` 를 세고,
     * 이쪽은 실제로 도착해 집계된 것을 센다.
     */
    public function reflectionRate(): float
    {
        if ($this->sentCount === 0) {
            return 0.0;
        }

        return count($this->matched) / $this->sentCount;
    }

    public function isClean(): bool
    {
        return $this->missing === [] && $this->duplicated === [];
    }
}
