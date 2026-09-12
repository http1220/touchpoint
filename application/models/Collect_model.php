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
