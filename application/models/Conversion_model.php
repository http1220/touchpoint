<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 전환 기록.
 *
 * **전환 기록과 아웃박스 적재가 한 트랜잭션이다.** 이게 이 모델의 존재
 * 이유다 — 나눠 두면 "전환은 있는데 전송 지시가 없다" 또는 그 반대가
 * 만들어진다. → docs/outbox-and-channels.md 3장
 */
class Conversion_model extends CI_Model
{
	const TABLE = 'conversions';

	/**
	 * 전환을 기록하고 채널 수만큼 아웃박스에 적재한다.
	 *
	 * @param array $c    type · value_minor · currency · dedup_key · user_id · visit_id …
	 * @param array $names 적재할 채널 이름들
	 * @return array [id, uid_hex, enqueued, duplicated]
	 */
	public function createWithOutbox(array $c, array $names)
	{
		$this->load->model('outbox_model');

		$uidHex = bin2hex(tp_uuid7());
		$now    = tp_now_utc();

		$this->db->trans_begin();

		$this->db->query(
			'INSERT INTO '.self::TABLE.' (
				conversion_uid, user_id, visit_id, type, value_minor, currency, dedup_key, occurred_at
			) VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?, ?)',
			array(
				$uidHex,
				isset($c['user_id']) ? (int) $c['user_id'] : NULL,
				isset($c['visit_id']) ? (int) $c['visit_id'] : NULL,
				$c['type'],
				isset($c['value_minor']) ? (int) $c['value_minor'] : NULL,
				isset($c['currency']) ? strtoupper((string) $c['currency']) : NULL,
				$c['dedup_key'],
				$c['occurred_at'] ?? $now,
			)
		);

		$id = (int) $this->db->insert_id();

		if ($id === 0)
		{
			// dedup_key UNIQUE 에 걸렸다. 같은 전환이 이미 있다.
			$this->db->trans_rollback();

			return array('id' => NULL, 'uid_hex' => NULL, 'enqueued' => 0, 'duplicated' => TRUE);
		}

		/*
		 * 매체에 보낼 payload 를 여기서 만든다.
		 *
		 * 어댑터가 아니라 적재 시점에 고정하는 이유: 나중에 회원 정보나
		 * 환율이 바뀌어도 **그 시점의 전환**이 보내져야 한다. 워커가
		 * 재시도할 때 DB 를 다시 읽으면 값이 달라질 수 있다.
		 */
		$payload = array(
			'conversion_uid'   => $uidHex,
			'type'             => $c['type'],
			'value_minor'      => $c['value_minor'] ?? NULL,
			'currency'         => $c['currency'] ?? NULL,
			'client_id'        => $c['client_id'] ?? '',
			'user_uid'         => $c['user_uid'] ?? '',
			'occurred_at_unix' => strtotime($c['occurred_at'] ?? $now),
		);

		$enqueued = 0;

		foreach ($names as $name)
		{
			$enqueued += $this->outbox_model->enqueue($id, $name, $payload);
		}

		$this->db->trans_commit();

		return array('id' => $id, 'uid_hex' => $uidHex, 'enqueued' => $enqueued, 'duplicated' => FALSE);
	}
}
