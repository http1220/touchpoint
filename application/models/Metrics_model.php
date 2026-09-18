<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 지표 조회. 읽기만 한다.
 *
 * 커넥션을 인자로 받는다 — 어느 커넥션에서 읽을지는 컨트롤러가 정할 일이고,
 * 이 모델의 조회는 전부 지연을 감수해도 되는 것들이다.
 * Collect_model::recent() 과 같은 방식이다.
 *
 * **집계를 채널별로 쪼개는 것이 이 모델의 규칙이다.**
 * 한 숫자로 합치면 아무 데도 보내지 않는 noop 이 분모를 채워
 * "전송 성공률 100%" 를 만든다. 실제로 그랬다 — 9303건 중 9300건이
 * noop 이었다. → docs/benchmarks.md 5장
 *
 * 화면 한 번에 쿼리 6개다. 지표 화면이 스스로 부하가 되면 안 된다.
 */
class Metrics_model extends CI_Model
{
	/**
	 * 아웃박스 상태 목록.
	 *
	 * GROUP BY 결과에 없는 상태는 "0건" 이지 "모른다" 가 아니다.
	 * 화면에 자리를 남겨 두려고 목록으로 고정한다.
	 */
	const OUTBOX_STATUSES = array('pending', 'sending', 'sent', 'failed', 'dead');

	/**
	 * 아웃박스 적재를 채널 × 상태로.
	 *
	 * 상태만으로 묶으면 "sent 9300" 이 나오는데, 그 대부분이 noop 이면
	 * 그 숫자는 매체에 대해 아무 말도 하지 않는다.
	 *
	 * `attempt >= 2` 를 같이 센다 — **재시도 후 최종 성공률**(api-spec 7장)의
	 * 분모가 그것이기 때문이다. 전체 적재로 나누면 한 번에 통과한 것까지
	 * 분모에 들어가 "백오프가 잘 듣는다" 가 항상 참이 된다.
	 *
	 * @return array rows[channel][status|_total|_retried|_recovered] · totals · grand
	 */
	public function outboxByChannel($db)
	{
		$raw = $db->query(
			'SELECT channel, status,
			        COUNT(*) AS n,
			        SUM(CASE WHEN attempt >= 2 THEN 1 ELSE 0 END) AS retried
			   FROM dispatch_outbox
			  GROUP BY channel, status'
		)->result_array();

		$rows   = array();
		$totals = array_fill_keys(self::OUTBOX_STATUSES, 0);
		$grand  = 0;

		foreach ($raw as $r)
		{
			$ch = (string) $r['channel'];
			$st = (string) $r['status'];
			$n  = (int) $r['n'];

			if ( ! isset($rows[$ch]))
			{
				$rows[$ch] = array_fill_keys(self::OUTBOX_STATUSES, 0);
				$rows[$ch]['_total']     = 0;
				$rows[$ch]['_retried']   = 0;
				$rows[$ch]['_recovered'] = 0;
			}

			// ENUM 에 없는 값이 올 일은 없지만, 와도 화면이 깨지지 않게 둔다.
			$rows[$ch][$st] = $n;
			$rows[$ch]['_total'] += $n;
			$rows[$ch]['_retried'] += (int) $r['retried'];

			// 재시도를 겪고도 결국 sent 로 끝난 것. 회복한 것들이다.
			if ($st === 'sent')
			{
				$rows[$ch]['_recovered'] += (int) $r['retried'];
			}

			if (isset($totals[$st]))
			{
				$totals[$st] += $n;
			}

			$grand += $n;
		}

		ksort($rows);

		return array('rows' => $rows, 'totals' => $totals, 'grand' => $grand);
	}

	/**
	 * 채널별 전송 시도·도달·지연.
	 *
	 * **여기서 나오는 성공률은 "도달률" 이다** — 매체가 2xx 를 돌려줬다는 뜻이지
	 * 매체 보고서에 실렸다는 뜻이 아니다. 반영률은 되읽어 대조해야만 알 수 있고
	 * (`cli/verify ga4`), 이 테이블에는 그 답이 없다. → docs/benchmarks.md 5-1
	 *
	 * 무응답(`http_status IS NULL`)은 실패로 센다. 타임아웃·커넥션 실패가
	 * 여기 들어온다 — 세지 않으면 분모가 조용히 줄어 도달률이 올라간다.
	 *
	 * p95 는 윈도우 함수로 뽑는다. MySQL 8 에 PERCENTILE_CONT 가 없으므로
	 * PERCENT_RANK() 로 순위를 매기고 95% 지점 **이하의 최댓값**을 고른다 —
	 * floor(0.95·(N−1))+1 번째 값이다. nearest-rank(ceil(0.95·N))와 같거나 한 순위 낮다.
	 * **표본이 얇으면 p95 는 가장 느린 값을 뺀다**(N 2~20 이면 두 번째로 큰 값).
	 * 그래서 최대와 표본 수를 같이 낸다. 실측 → docs/benchmarks.md 3-2
	 * (전에는 "nearest-rank, 표본이 작으면 최댓값과 같아진다" 고 적혀 있었다 — 반대였다)
	 *
	 * 구간을 넷 다 낸다(api-spec 7장). 구간을 나눠 적는 이유가 **"합이 맞아야
	 * 어디가 느린지 안다"** 이고, 실제로 그 합이 안 맞아서 계측 코드의 버그를
	 * 찾았다 — 배치 선점 시간을 모든 행에 그대로 더하고 있었다.
	 * → docs/benchmarks.md 3장
	 *
	 * 대가: 같은 파티션에 정렬만 다른 윈도우가 넷이다. 지금 규모(1만 행 미만)
	 * 에서는 싸지만, 로그가 커지면 이 쿼리가 화면에서 제일 먼저 비싸진다.
	 * 그때 자를 곳은 `created_at` 범위다 — `ix_purge` 가 이미 있다.
	 *
	 * @return array<int, array> 시도 많은 채널부터
	 */
	public function dispatchByChannel($db)
	{
		return $db->query(
			'SELECT channel,
			        COUNT(*)                                            AS attempts,
			        SUM(ok_row)                                         AS ok_n,
			        COUNT(*) - SUM(ok_row)                              AS fail_n,
			        SUM(no_reply)                                       AS no_reply_n,
			        COUNT(DISTINCT outbox_id)                           AS outbox_n,

			        ROUND(AVG(parse_ms), 1)                             AS parse_avg,
			        MAX(CASE WHEN pr_parse <= 0.50 THEN parse_ms END)   AS parse_p50,
			        MAX(CASE WHEN pr_parse <= 0.95 THEN parse_ms END)   AS parse_p95,
			        MAX(parse_ms)                                       AS parse_max,

			        ROUND(AVG(db_ms), 1)                                AS db_avg,
			        MAX(CASE WHEN pr_db <= 0.50 THEN db_ms END)         AS db_p50,
			        MAX(CASE WHEN pr_db <= 0.95 THEN db_ms END)         AS db_p95,
			        MAX(db_ms)                                          AS db_max,

			        ROUND(AVG(send_ms), 1)                              AS send_avg,
			        MAX(CASE WHEN pr_send <= 0.50 THEN send_ms END)     AS send_p50,
			        MAX(CASE WHEN pr_send <= 0.95 THEN send_ms END)     AS send_p95,
			        MAX(send_ms)                                        AS send_max,

			        ROUND(AVG(total_ms), 1)                             AS total_avg,
			        MAX(CASE WHEN pr_total <= 0.50 THEN total_ms END)   AS total_p50,
			        MAX(CASE WHEN pr_total <= 0.95 THEN total_ms END)   AS total_p95,
			        MAX(total_ms)                                       AS total_max,

			        MIN(created_at)                                     AS first_at,
			        MAX(created_at)                                     AS last_at
			   FROM (
			        SELECT channel, outbox_id, parse_ms, db_ms, send_ms, total_ms, created_at,
			               CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END AS ok_row,
			               CASE WHEN http_status IS NULL THEN 1 ELSE 0 END              AS no_reply,
			               PERCENT_RANK() OVER (PARTITION BY channel ORDER BY parse_ms) AS pr_parse,
			               PERCENT_RANK() OVER (PARTITION BY channel ORDER BY db_ms)    AS pr_db,
			               PERCENT_RANK() OVER (PARTITION BY channel ORDER BY send_ms)  AS pr_send,
			               PERCENT_RANK() OVER (PARTITION BY channel ORDER BY total_ms) AS pr_total
			          FROM dispatch_log
			        ) t
			  GROUP BY channel
			  ORDER BY attempts DESC'
		)->result_array();
	}

	/**
	 * 채널별 HTTP 상태 분포.
	 *
	 * 도달률 한 줄로는 안 보이는 것이 여기 있다 — GA4 는 운영 엔드포인트가
	 * `204`, 검증 엔드포인트가 `200` 이다. **둘 다 2xx 인데 200 쪽은 적재되지
	 * 않는다.** 대조에서 그 5건을 "매체가 버렸다" 로 읽을 뻔했다.
	 * → docs/benchmarks.md 5-1 곁가지
	 *
	 * @return array channel => array('total' => n, 'rows' => array(...))
	 */
	public function httpStatusByChannel($db)
	{
		$raw = $db->query(
			'SELECT channel, http_status, COUNT(*) AS n
			   FROM dispatch_log
			  GROUP BY channel, http_status
			  ORDER BY channel, http_status'
		)->result_array();

		$out = array();

		foreach ($raw as $r)
		{
			$ch = (string) $r['channel'];

			if ( ! isset($out[$ch]))
			{
				$out[$ch] = array('total' => 0, 'rows' => array());
			}

			$out[$ch]['rows'][] = array(
				// NULL 은 "상태를 못 받았다" 다. 0 으로 뭉개면 4xx 와 구분이 사라진다.
				'status' => $r['http_status'] === NULL ? NULL : (int) $r['http_status'],
				'n'      => (int) $r['n'],
			);

			$out[$ch]['total'] += (int) $r['n'];
		}

		return $out;
	}

	/**
	 * 재시도 분포. 채널별로 쪼갠다.
	 *
	 * attempt 별 건수를 한 덩어리로 내면 noop 이 분포를 덮는다.
	 * "몇 번째 시도에서 대부분 성공하는가" 는 채널마다 다른 질문이다
	 * → docs/benchmarks.md 4장의 미결 항목
	 *
	 * @return array channel => array('total' => n, 'rows' => array(...))
	 */
	public function attemptsByChannel($db)
	{
		$raw = $db->query(
			'SELECT channel, attempt,
			        COUNT(*) AS n,
			        SUM(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS ok_n
			   FROM dispatch_log
			  GROUP BY channel, attempt
			  ORDER BY channel, attempt'
		)->result_array();

		$out = array();

		foreach ($raw as $r)
		{
			$ch = (string) $r['channel'];

			if ( ! isset($out[$ch]))
			{
				$out[$ch] = array('total' => 0, 'rows' => array());
			}

			$out[$ch]['rows'][] = array(
				'attempt' => (int) $r['attempt'],
				'n'       => (int) $r['n'],
				'ok_n'    => (int) $r['ok_n'],
			);

			$out[$ch]['total'] += (int) $r['n'];
		}

		return $out;
	}

	/**
	 * 전환 건수와 적재 상태.
	 *
	 * 전환 수만 내면 분모가 없다. "전환이 생기면 아웃박스에 적재된다" 는
	 * 아웃박스 패턴의 전제이므로, 그 전제가 지켜졌는지를 같은 줄에 낸다 —
	 * 적재되지 않은 전환이 있으면 트랜잭션 어딘가가 새고 있다는 뜻이다.
	 *
	 * 식별자(conversion_uid)·회원 정보는 내리지 않는다. 이 화면은 인증이 없다.
	 *
	 * @return array<int, array> type · n · enqueued · any_sent
	 */
	public function conversionsByType($db)
	{
		return $db->query(
			'SELECT c.type,
			        COUNT(*)                                           AS n,
			        SUM(CASE WHEN o.n_ch   > 0 THEN 1 ELSE 0 END)      AS enqueued,
			        SUM(CASE WHEN o.n_sent > 0 THEN 1 ELSE 0 END)      AS any_sent
			   FROM conversions c
			   LEFT JOIN (
			        SELECT conversion_id,
			               COUNT(*)                                              AS n_ch,
			               SUM(CASE WHEN status = \'sent\' THEN 1 ELSE 0 END)     AS n_sent
			          FROM dispatch_outbox
			         GROUP BY conversion_id
			        ) o ON o.conversion_id = c.id
			  GROUP BY c.type
			  ORDER BY n DESC'
		)->result_array();
	}

	/**
	 * 광고가 만든 것 — 매체별 전환 귀속.
	 *
	 * 이 시스템이 존재하는 이유가 이 질문이다: **어느 광고가 결제를 만들었나.**
	 * 파이프라인이 건강하다는 숫자(적재·도달·재시도)는 그 답이 아니다.
	 *
	 * 한 전환을 **최초 유입(first)에도, 마지막 유입(last)에도** 붙여서 두 번 센다.
	 * 정산에서 다투는 자리가 정확히 여기라서 한 기준을 고르지 않는다 —
	 * 표를 합치는 쪽은 App\Metrics\AdAttribution (순수 계산 · 테스트 있음).
	 *
	 * 분모를 위해 전환 전체와 방문·광고 접점이 붙은 전환 수를 따로 낸다.
	 * **비율만 내면 "광고가 다 만들었다" 로 읽힌다** — 대량 적재된 전환에는
	 * 방문이 아예 없다(쿠키 없이 서버에서 만든 것).
	 *
	 * 금액은 conversions.value_minor 를 그대로 더한다. KRW 의 minor unit 은 원이다
	 * (9,900원 = 9900) → App\Attribution\ConversionInput.
	 *
	 * 식별자·회원 정보는 내리지 않는다. 이 화면은 인증이 없다.
	 *
	 * @return array{total: int, with_visit: int, with_ad: int, rows: array<int, array>}
	 */
	public function adAttribution($db)
	{
		$totals = $db->query(
			'SELECT COUNT(*)                                            AS total,
			        SUM(CASE WHEN c.visit_id IS NOT NULL THEN 1 ELSE 0 END) AS with_visit
			   FROM conversions c'
		)->row_array();

		$withAd = $db->query(
			'SELECT COUNT(DISTINCT c.id) AS n
			   FROM conversions c
			   JOIN touchpoints t ON t.visit_id = c.visit_id
			  WHERE t.pid        IS NOT NULL
			     OR t.utm_source IS NOT NULL
			     OR t.gclid      IS NOT NULL
			     OR t.fbclid     IS NOT NULL'
		)->row_array();

		/*
		 * position 으로 묶으므로 한 방문에 first·last 가 다 있어도 각 묶음 안에서는
		 * 전환이 한 번씩만 센다. 묶음을 가로질러 더하지 않는 것은 뷰가 아니라
		 * AdAttribution 이 책임진다(금액은 last 에서만).
		 */
		/*
		 * 전환 유형을 함께 내린다. **환불 전환의 value_minor 는 양수**다
		 * (원 결제 금액 그대로 → Payment_model::refundConversion). 유형을 보지 않고 더하면
		 * 환불이 매출로 잡힌다. 유형별로 나누는 일은 AdAttribution 이 한다.
		 */
		$rows = $db->query(
			'SELECT t.position,
			        t.utm_source                    AS source,
			        c.type,
			        c.currency,
			        COUNT(DISTINCT c.id)            AS conversions,
			        SUM(COALESCE(c.value_minor, 0)) AS value_minor
			   FROM conversions c
			   JOIN touchpoints t ON t.visit_id = c.visit_id
			  WHERE t.pid        IS NOT NULL
			     OR t.utm_source IS NOT NULL
			     OR t.gclid      IS NOT NULL
			     OR t.fbclid     IS NOT NULL
			  GROUP BY t.position, t.utm_source, c.type, c.currency'
		)->result_array();

		return array(
			'total'      => (int) ($totals['total'] ?? 0),
			'with_visit' => (int) ($totals['with_visit'] ?? 0),
			'with_ad'    => (int) ($withAd['n'] ?? 0),
			'rows'       => $rows,
		);
	}

	/**
	 * 방문과 접점.
	 *
	 * **"접점이 있는 방문 ÷ 전체 방문" 은 지표가 아니다.** 직접 유입도
	 * TouchpointResolver 규칙 4에 따라 last 접점을 하나 받으므로 99.8% 가 나온다.
	 * 그 숫자는 시스템이 건강하다는 뜻이 아니라 **분모를 잘못 골랐다**는 뜻이었다.
	 * → docs/benchmarks.md 5장
	 *
	 * 그래서 유입 파라미터가 실제로 실린 접점(ad_n)을 따로 센다. 어트리뷰션
	 * 보존율을 말하려면 분모가 이쪽이어야 한다.
	 *
	 * @return array 한 행
	 */
	public function visitFunnel($db)
	{
		$row = $db->query(
			'SELECT COUNT(*)                                          AS visits,
			        SUM(CASE WHEN t.any_n   > 0 THEN 1 ELSE 0 END)    AS with_tp,
			        SUM(CASE WHEN t.first_n > 0 THEN 1 ELSE 0 END)    AS with_first,
			        SUM(CASE WHEN t.last_n  > 0 THEN 1 ELSE 0 END)    AS with_last,
			        SUM(CASE WHEN t.ad_n    > 0 THEN 1 ELSE 0 END)    AS with_ad
			   FROM visits v
			   LEFT JOIN (
			        SELECT visit_id,
			               COUNT(*)                                             AS any_n,
			               SUM(CASE WHEN position = \'first\' THEN 1 ELSE 0 END) AS first_n,
			               SUM(CASE WHEN position = \'last\'  THEN 1 ELSE 0 END) AS last_n,
			               SUM(CASE WHEN pid        IS NOT NULL
			                          OR utm_source IS NOT NULL
			                          OR gclid      IS NOT NULL
			                          OR fbclid     IS NOT NULL
			                        THEN 1 ELSE 0 END)                          AS ad_n
			          FROM touchpoints
			         GROUP BY visit_id
			        ) t ON t.visit_id = v.id'
		)->row_array();

		// 방문이 0건이면 SUM 은 NULL 이다. 화면에서 "—" 로 갈리지 않게 여기서 0으로.
		return array(
			'visits'     => (int) ($row['visits'] ?? 0),
			'with_tp'    => (int) ($row['with_tp'] ?? 0),
			'with_first' => (int) ($row['with_first'] ?? 0),
			'with_last'  => (int) ($row['with_last'] ?? 0),
			'with_ad'    => (int) ($row['with_ad'] ?? 0),
		);
	}
}
