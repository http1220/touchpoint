<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 수집 이벤트 적재.
 *
 * 쓰기만 한다. 판정은 컨트롤러와 src/ 에 있다.
 */
class Collect_model extends CI_Model
{
	const TABLE = 'collect_events';

	/** 허용하는 이벤트 이름. 화이트리스트 밖은 받지 않는다. */
	const EVENTS = array('page_view', 'impression', 'click');

	const TRANSPORTS = array('fetch', 'beacon', 'server');

	public function record(array $e)
	{
		$this->db->query(
			'INSERT INTO '.self::TABLE.' (
				visit_id, event, work_id, transport, origin, occurred_at, received_at
			) VALUES (?, ?, ?, ?, ?, ?, ?)',
			array(
				(int) $e['visit_id'],
				$e['event'],
				isset($e['work_id']) && $e['work_id'] !== NULL ? (int) $e['work_id'] : NULL,
				$e['transport'],
				isset($e['origin']) && $e['origin'] !== '' ? mb_substr((string) $e['origin'], 0, 255) : NULL,
				$e['occurred_at'],
				tp_now_utc(),
			)
		);

		return (int) $this->db->insert_id();
	}

	/**
	 * 이 방문의 최근 이벤트. 진단 화면에서 쓴다.
	 *
	 * 읽기 전용이라 배정받은 복제본에서 읽어도 된다 — 다만 방금 넣은 건이
	 * 안 보일 수 있다. 그 지연을 눈으로 보는 것도 목적이다.
	 */
	/**
	 * 노출 배치를 **한 INSERT** 로. 배치로 받은 것을 행마다 왕복하면 배치의 의미가 없다.
	 * 항목 수는 ImpressionBatch::MAX_ITEMS 로 막혀 있어 문장 길이가 폭주하지 않는다.
	 *
	 * @param list<array{work_id:int, slot:string}> $items
	 * @return int 넣은 행 수
	 */
	public function recordImpressions($visitId, array $items, $transport, $origin, $statDate)
	{
		if ($items === array())
		{
			return 0;
		}

		$now    = tp_now_utc();
		$rows   = array();
		$params = array();

		foreach ($items as $item)
		{
			$rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
			array_push($params,
				(int) $visitId, 'impression', (int) $item['work_id'], $item['slot'], $statDate,
				$transport, $origin !== '' ? mb_substr((string) $origin, 0, 255) : NULL, $now, $now);
		}

		$this->db->query(
			'INSERT INTO '.self::TABLE.' (
				visit_id, event, work_id, slot, stat_date, transport, origin, occurred_at, received_at
			) VALUES '.implode(', ', $rows),
			$params
		);

		return (int) $this->db->affected_rows();
	}

	/**
	 * 클릭 한 건. `INSERT IGNORE` — dedup_key UNIQUE 에 걸리면 0 이다.
	 *
	 * 미리 SELECT 해서 거르지 않는다. 더블 클릭은 거의 동시에 오고, 둘 다
	 * SELECT 를 통과한다 → Conversion_model 과 같은 규칙.
	 *
	 * @return bool 새로 기록했는가
	 */
	public function recordClick($visitId, $workId, $slot, $statDate, $dedupKey)
	{
		$now = tp_now_utc();

		$this->db->query(
			'INSERT IGNORE INTO '.self::TABLE.' (
				visit_id, event, work_id, slot, stat_date, dedup_key, transport, origin, occurred_at, received_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
			array((int) $visitId, 'click', (int) $workId, $slot, $statDate, $dedupKey, 'server', $now, $now)
		);

		return (int) $this->db->affected_rows() === 1;
	}

	/**
	 * 자리별 CTR. 최근 N일, 노출일 기준.
	 *
	 * 노출이 0 인 자리의 클릭은 CTR 을 만들지 않는다(NULL). 0 으로 나누지 않으려는
	 * 것이 아니라, 노출 수집이 빠진 자리를 "CTR 무한대" 로 보이게 하지 않으려는 것이다.
	 */
	public function ctrBySlot($db, $days = 7)
	{
		return $db->query(
			'SELECT stat_date, slot,
			        SUM(event = "impression") AS impressions,
			        SUM(event = "click")      AS clicks,
			        IF(SUM(event = "impression") = 0, NULL,
			           ROUND(SUM(event = "click") / SUM(event = "impression") * 100, 2)) AS ctr_pct
			   FROM '.self::TABLE.'
			  WHERE stat_date >= UTC_DATE() - INTERVAL ? DAY
			    AND slot IS NOT NULL
			  GROUP BY stat_date, slot
			  ORDER BY stat_date DESC, slot',
			array(max(1, (int) $days))
		)->result_array();
	}

	public function recent($db, $visitId, $limit = 20)
	{
		return $db->query(
			'SELECT event, work_id, transport, origin, occurred_at, received_at
			   FROM '.self::TABLE.'
			  WHERE visit_id = ?
			  ORDER BY id DESC
			  LIMIT '.(int) $limit,
			array((int) $visitId)
		)->result_array();
	}
}
