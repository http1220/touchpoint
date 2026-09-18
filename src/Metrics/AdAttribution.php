<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * 광고 유입 → 전환 귀속. 지표 화면의 "광고가 만든 것" 표를 만든다.
 *
 * **같은 전환이라도 어느 접점에 붙이느냐에 따라 매체가 달라진다.**
 * 최초 유입(first)으로 세면 처음 데려온 매체가, 마지막 유입(last)으로 세면
 * 마지막으로 밀어 준 매체가 공을 가져간다. 광고비 정산에서 다투는 자리가
 * 정확히 여기라서, 이 화면은 **한 기준을 고르지 않고 둘을 나란히 놓는다.**
 *
 * 결제 자체의 방문 선택(결제 시점 방문 우선 · 없으면 가입 접점)은 다른 층의
 * 결정이다 → `Payment_model::attribution()`. 여기서는 그렇게 정해진 방문에
 * 달린 접점만 읽는다.
 *
 * DB 를 모른다. 행을 받아 표를 만드는 순수 계산이라 테스트가 붙는다.
 */
final class AdAttribution
{
    /** 매체 이름이 비어 있을 때 — utm_source 없이 pid·gclid 만 온 유입 */
    public const UNNAMED = '(이름 없음)';

    /**
     * @param list<array{position: string, source: ?string, conversions: int|string, value_minor: int|string, currency: ?string}> $rows
     *        position 은 'first' | 'last'. 그 밖의 값은 버린다 — 접점 종류가 늘어도 표가 깨지지 않게.
     * @return array{
     *     rows: list<array{source: string, first: int, last: int, value_minor: int, currency: ?string, moved: bool}>,
     *     first_total: int, last_total: int, value_total: int,
     *     currencies: list<string>, moved: bool
     * }
     */
    public static function table(array $rows): array
    {
        $bySource = [];
        $currencies = [];

        foreach ($rows as $row) {
            $position = (string) ($row['position'] ?? '');
            if ($position !== 'first' && $position !== 'last') {
                continue;
            }

            $source = self::name($row['source'] ?? null);
            $bySource[$source] ??= ['source' => $source, 'first' => 0, 'last' => 0, 'value_minor' => 0, 'currency' => null];

            $bySource[$source][$position] += (int) ($row['conversions'] ?? 0);

            /*
             * 금액은 **마지막 유입 기준 한 번만** 더한다. 두 기준을 다 더하면
             * 같은 결제를 두 번 세어 합계가 실제 매출의 두 배가 된다.
             */
            if ($position === 'last') {
                $bySource[$source]['value_minor'] += (int) ($row['value_minor'] ?? 0);

                $currency = $row['currency'] ?? null;
                if (is_string($currency) && $currency !== '') {
                    $bySource[$source]['currency'] ??= $currency;
                    $currencies[$currency] = true;
                }
            }
        }

        $out = [];
        foreach ($bySource as $entry) {
            $entry['moved'] = $entry['first'] !== $entry['last'];
            $out[] = $entry;
        }

        // 마지막 유입 기준 전환이 많은 순. 같으면 최초 기준, 그다음 이름으로 — 순서가 매번 흔들리지 않게.
        usort($out, static function (array $a, array $b): int {
            return [$b['last'], $b['first'], $a['source']] <=> [$a['last'], $a['first'], $b['source']];
        });

        $firstTotal = 0;
        $lastTotal = 0;
        $valueTotal = 0;
        $moved = false;
        foreach ($out as $entry) {
            $firstTotal += $entry['first'];
            $lastTotal += $entry['last'];
            $valueTotal += $entry['value_minor'];
            $moved = $moved || $entry['moved'];
        }

        $names = array_keys($currencies);
        sort($names);

        return [
            'rows' => $out,
            'first_total' => $firstTotal,
            'last_total' => $lastTotal,
            'value_total' => $valueTotal,
            'currencies' => $names,
            'moved' => $moved,
        ];
    }

    /** 빈 이름·공백만 있는 이름을 하나로 모은다 */
    private static function name(?string $source): string
    {
        $source = $source === null ? '' : trim($source);

        return $source === '' ? self::UNNAMED : $source;
    }
}
