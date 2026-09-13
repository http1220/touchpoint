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
			/*
			 * INSERT IGNORE 다.
			 *
			 * dedup_key 가 겹치면 평범한 INSERT 는 오류를 내고, db_debug 가
			 * 켜져 있으면 CI3 가 에러 화면을 띄우고 요청을 끝낸다. 중복 전환은
			 * **정상적으로 일어나는 일**이라 예외로 다루면 안 된다 —
			 * 사용자가 결제 버튼을 두 번 누르면 바로 이 경로다.
			 *
			 * IGNORE 로 두면 중복은 affected_rows 0 으로 조용히 돌아오고,
			 * 그걸 duplicated 로 호출자에게 알린다. 경쟁 상태에도 안전하다 —
			 * 미리 SELECT 해서 막으면 동시 요청 둘이 다 통과한다.
			 */
			'INSERT IGNORE INTO '.self::TABLE.' (
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

		$affected = (int) $this->db->affected_rows();
		$id       = (int) $this->db->insert_id();

		if ($affected === 0 OR $id === 0)
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

	/**
	 * dedup_key 로 이미 기록된 전환을 찾는다.
	 *
	 * **`createWithOutbox()` 가 duplicated 를 돌려준 뒤에만 부른다.**
	 * 먼저 불러서 중복을 거르는 용도로 쓰면 안 된다 — 그 순서로 두면
	 * 동시 요청 둘이 다 SELECT 를 통과한다. 판정은 UNIQUE 가 한다.
	 *
	 * 이 메서드가 필요한 이유는 INSERT IGNORE 가 **충돌한 행을 알려주지
	 * 않기** 때문이다. affected_rows 0 이 전부라서, 멱등 응답에 실을
	 * 기존 conversion_uid 를 따로 읽어야 한다 → docs/api-spec.md 4장
	 *
	 * 복제본이 아니라 $this->db(프라이머리)로 읽는다. 방금 다른 요청이
	 * 커밋한 행이라 복제본에서는 아직 안 보일 수 있고, 그러면 멱등해야 할
	 * 재요청이 "중복인데 행이 없다" 로 떨어진다 → ADR-007
	 *
	 * @param string $dedupKey
	 * @return array|null [id, uid_hex]
	 */
	public function findByDedupKey($dedupKey)
	{
		$row = $this->db
			->query(
				/*
				 * HEX() 는 대문자를 돌려주고 bin2hex() 는 소문자를 돌려준다.
				 * 신규 응답(createWithOutbox)과 중복 응답이 같은 전환에 대해
				 * 다른 문자열을 내면 호출자가 두 값을 다른 것으로 센다.
				 */
				'SELECT id, LOWER(HEX(conversion_uid)) AS uid_hex
				   FROM '.self::TABLE.'
				  WHERE dedup_key = ?
				  LIMIT 1',
				array((string) $dedupKey)
			)
			->row();

		return $row ? array('id' => (int) $row->id, 'uid_hex' => (string) $row->uid_hex) : NULL;
	}
}
