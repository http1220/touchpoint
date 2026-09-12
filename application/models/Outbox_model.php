<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\DispatchResult;

/**
 * 매체 전송 아웃박스.
 *
 * 판정은 여기 없다. 재시도 간격은 `src/Dispatch/BackoffPolicy`,
 * 성공/재시도/포기는 `src/Channel/DispatchResult` 가 정하고,
 * 이 모델은 그 결과를 행에 옮긴다.
 *
 * 쓰기는 전부 `$this->db`(write 그룹, 프라이머리)로 간다. 선점 직후
 * 그 행을 다시 읽어야 하므로 복제본을 거칠 수 없다.
 */
class Outbox_model extends CI_Model
{
	const TABLE = 'dispatch_outbox';

	/**
	 * 전송할 것을 적재한다.
	 *
	 * **전환을 기록하는 트랜잭션 안에서 호출한다.** 커밋이 곧
	 * "보내기로 확정됨" 이고, 롤백되면 이 행도 함께 사라진다 —
	 * "전환은 없는데 전송은 나갔다" 가 구조적으로 불가능해진다.
	 *
	 * 중복 적재는 UNIQUE (conversion_id, channel) 이 막는다. 애플리케이션이
	 * 먼저 SELECT 해서 막으면 동시 요청 두 개가 둘 다 통과한다.
	 */
	public function enqueue($conversionId, $channel, array $payload)
	{
		$this->db->query(
			'INSERT IGNORE INTO '.self::TABLE.' (
				conversion_id, channel, payload, status, attempt, next_retry_at
			) VALUES (?, ?, ?, ?, 0, ?)',
			array(
				(int) $conversionId,
				$channel,
				json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'pending',
				tp_now_utc(),
			)
		);

		return (int) $this->db->affected_rows();
	}

	/**
	 * 보낼 것을 선점한다.
	 *
	 * 선점과 상태 변경이 **한 트랜잭션 안에서** 끝나야 한다. 잠금을 풀고
	 * 나서 상태를 바꾸면 그 틈에 다른 워커가 같은 행을 집는다.
	 *
	 * SKIP LOCKED 는 "다른 워커가 잠근 행은 건너뛴다" 는 뜻이다. 없으면
	 * 워커들이 같은 행을 두고 줄을 서서, 여럿을 띄운 의미가 사라진다.
	 *
	 * @return array 선점한 행들. 각 행에 id·channel·payload·attempt
	 */
	public function claim($limit)
	{
		$limit = max(1, (int) $limit);

		$this->db->trans_begin();

		$rows = $this->db->query(
			'SELECT id, conversion_id, channel, payload, attempt
			   FROM '.self::TABLE.'
			  WHERE status = ? AND next_retry_at <= ?
			  ORDER BY id
			  LIMIT '.$limit.'
			  FOR UPDATE SKIP LOCKED',
			array('pending', tp_now_utc())
		)->result_array();

		if ($rows === array())
		{
			$this->db->trans_rollback();

			return array();
		}

		$ids = array_column($rows, 'id');
		$in  = implode(',', array_map('intval', $ids));

		// 잠금이 살아 있는 동안 상태를 바꾼다.
		$this->db->query(
			'UPDATE '.self::TABLE.'
			    SET status = ?, attempt = attempt + 1, claimed_at = ?
			  WHERE id IN ('.$in.')',
			array('sending', tp_now_utc())
		);

		$this->db->trans_commit();

		// attempt 는 방금 1 늘었다. 호출자가 백오프 계산에 쓰는 값이므로
		// 갱신된 값을 돌려준다.
		foreach ($rows as &$row)
		{
			$row['attempt'] = (int) $row['attempt'] + 1;
		}

		return $rows;
	}

	/** 전송 결과를 행에 옮긴다. */
	public function applyResult($id, DispatchResult $result, DateTimeImmutable $nextRetryAt = NULL)
	{
		if ($result->isSent())
		{
			$this->db->query(
				'UPDATE '.self::TABLE.' SET status = ?, sent_at = ?, last_error = NULL WHERE id = ?',
				array('sent', tp_now_utc(), (int) $id)
			);

			return 'sent';
		}

		// 재시도할 수 있어도 다음 시각이 없으면(백오프 소진) 포기다.
		if ($result->shouldRetry() && $nextRetryAt !== NULL)
		{
			$this->db->query(
				'UPDATE '.self::TABLE.'
				    SET status = ?, next_retry_at = ?, claimed_at = NULL, last_error = ?
				  WHERE id = ?',
				array('pending', $nextRetryAt->format('Y-m-d H:i:s.v'), self::trim($result->error), (int) $id)
			);

			return 'failed';
		}

		$this->db->query(
			'UPDATE '.self::TABLE.' SET status = ?, claimed_at = NULL, last_error = ? WHERE id = ?',
			array('dead', self::trim($result->error), (int) $id)
		);

		return 'dead';
	}

	/**
	 * 좀비 회수.
	 *
	 * 워커가 전송 도중 죽으면 행이 `sending` 에 남는다. 아무도 다시 보내지
	 * 않고, 아무도 실패했다고 말해 주지 않는다. 일정 시간 지난 것을
	 * `pending` 으로 되돌린다.
	 *
	 * **attempt 는 되돌리지 않는다.** 죽기 전에 실제로 보냈을 수도 있어서,
	 * 시도 횟수를 깎으면 무한히 재시도할 수 있게 된다.
	 *
	 * @param int $olderThanSeconds 전송 타임아웃보다 넉넉히 잡는다
	 */
	public function reclaimZombies($olderThanSeconds = 120)
	{
		$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->sub(new DateInterval('PT'.max(1, (int) $olderThanSeconds).'S'))
			->format('Y-m-d H:i:s.v');

		$this->db->query(
			'UPDATE '.self::TABLE.'
			    SET status = ?, claimed_at = NULL,
			        last_error = ?
			  WHERE status = ? AND claimed_at IS NOT NULL AND claimed_at < ?',
			array('pending', '워커가 전송 중 사라져 회수됨', 'sending', $cutoff)
		);

		return (int) $this->db->affected_rows();
	}

	/** 전송 시도 계측. 구간을 나눠야 어디가 느린지 안다. */
	public function log(array $e)
	{
		$this->db->query(
			'INSERT INTO dispatch_log (
				outbox_id, trace_id, channel, attempt, http_status,
				parse_ms, db_ms, send_ms, total_ms, created_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			array(
				(int) $e['outbox_id'],
				$e['trace_id'] ?? NULL,
				$e['channel'],
				(int) $e['attempt'],
				isset($e['http_status']) ? (int) $e['http_status'] : NULL,
				(int) $e['parse_ms'],
				(int) $e['db_ms'],
				(int) $e['send_ms'],
				(int) $e['total_ms'],
				tp_now_utc(),
			)
		);
	}

	/** 상태별 건수. 진단·검증에 쓴다. */
	public function counts()
	{
		$rows = $this->db
			->query('SELECT status, COUNT(*) AS n FROM '.self::TABLE.' GROUP BY status')
			->result_array();

		$out = array();

		foreach ($rows as $r)
		{
			$out[$r['status']] = (int) $r['n'];
		}

		return $out;
	}

	private static function trim($error)
	{
		return $error === NULL ? NULL : mb_substr((string) $error, 0, 512);
	}
}
