<?php

declare(strict_types=1);

namespace App\Verify;

use App\Channel\HttpClient;
use RuntimeException;

/**
 * GA4 Data API 로 **우리가 보낸 것을 되읽는다.**
 *
 * 왜 필요한가 — Measurement Protocol 운영 엔드포인트는 페이로드가 틀려도
 * `204` 를 준다. 그래서 전송 로그의 성공률은 **"도달했다"** 까지만 말할 수
 * 있고 **"집계됐다"** 는 말하지 못한다. 그 둘을 가르려면 매체 쪽에서
 * 되읽어 와 대조하는 수밖에 없다 → docs/benchmarks.md 5장
 *
 * **속성 ID 는 측정 ID 와 다르다.** 측정 ID 는 `G-XXXXXXXXXX`(스트림),
 * 속성 ID 는 숫자(`123456789`)다. Data API 는 숫자 쪽을 쓴다 —
 * 여기서 한 번 막히는 자리라 적어 둔다.
 */
final class Ga4Reader
{
    public const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private const BASE = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    public function __construct(
        private readonly HttpClient $http,
        private readonly GoogleServiceAccount $account,
        private readonly string $propertyId,
        private readonly int $timeoutMs = 20000,
    ) {
        if ($this->propertyId === '') {
            throw new RuntimeException('GA4 속성 ID 가 비어 있습니다. 측정 ID(G-…) 가 아니라 숫자입니다.');
        }
    }

    /**
     * 기간 안에 집계된 `transaction_id` 목록.
     *
     * 우리가 보낸 purchase 이벤트에는 `transaction_id` 로 전환 UID 를 싣는다.
     * 그래서 이 목록과 우리 `conversions` 를 맞대면 **한 건씩 대조**가 된다.
     * 건수만 비교하면 "몇 개가 빠졌다" 까지는 알아도 "어느 것이" 는 모른다.
     *
     * @param string $start `YYYY-MM-DD` 또는 `NdaysAgo` · `today`
     * @return array<string,int> transaction_id => eventCount
     */
    public function transactionIds(string $start, string $end = 'today', int $limit = 100000): array
    {
        $body = $this->runReport([
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'transactionId']],
            'metrics' => [['name' => 'eventCount']],
            'limit' => $limit,

            // (not set) 행이 섞이면 대조가 흐려진다. 빈 것은 빼고 받는다.
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'transactionId',
                    'stringFilter' => ['matchType' => 'FULL_REGEXP', 'value' => '.+'],
                ],
            ],
        ]);

        $out = [];

        foreach ($body['rows'] ?? [] as $row) {
            $id = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $n = (int) ($row['metricValues'][0]['value'] ?? 0);

            if ($id !== '' && $id !== '(not set)') {
                $out[$id] = $n;
            }
        }

        return $out;
    }

    /**
     * 이벤트 이름별 건수. 대조 전에 "무엇이라도 들어왔는가" 를 보는 용도다.
     *
     * @return array<string,int>
     */
    public function eventCounts(string $start, string $end = 'today'): array
    {
        $body = $this->runReport([
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount']],
            'limit' => 200,
        ]);

        $out = [];

        foreach ($body['rows'] ?? [] as $row) {
            $out[(string) ($row['dimensionValues'][0]['value'] ?? '')]
                = (int) ($row['metricValues'][0]['value'] ?? 0);
        }

        return $out;
    }

    // ────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function runReport(array $request): array
    {
        $res = $this->http->postJson(
            self::BASE.$this->propertyId.':runReport',
            (string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Authorization' => 'Bearer '.$this->account->accessToken()],
            $this->timeoutMs
        );

        $body = $res->json();

        if (!$res->isSuccess()) {
            /*
             * 403 은 대개 "속성에 서비스 계정을 추가하지 않음" 이다.
             * 키 파일이 맞아도 그 계정이 속성 권한을 못 받았으면 여기서 막힌다 —
             * 인증(누구인가)과 인가(무엇을 볼 수 있는가)가 다른 단계라서 그렇다.
             */
            throw new RuntimeException(sprintf(
                'Data API 실패 (http=%s): %s',
                $res->status ?? '-',
                (string) ($body['error']['message'] ?? $res->error ?? $res->body)
            ));
        }

        return $body;
    }
}
