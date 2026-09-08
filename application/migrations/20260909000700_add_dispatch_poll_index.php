<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 폴링 인덱스. 일부러 따로 뗀 마이그레이션이다.
 *
 * 워커의 폴링 쿼리는 이것이다.
 *
 *   SELECT ... FROM dispatch_outbox
 *    WHERE status = 'pending' AND next_retry_at <= NOW(3)
 *    ORDER BY id LIMIT 100 FOR UPDATE SKIP LOCKED
 *
 * 컬럼 순서 (status, next_retry_at, id) 의 근거:
 *   등호(status) → 범위(next_retry_at) → 정렬(id).
 *   범위 조건 뒤의 컬럼은 인덱스로 정렬에 쓸 수 없으므로 id 가 마지막이다.
 *   (next_retry_at, status, id) 로 두면 status 를 등호로 활용하지 못한다.
 *
 * 인덱스를 분리한 이유는 실험 때문이다.
 *
 *   cli/migrate to 20260909000600   인덱스 없음 → EXPLAIN type: ALL
 *   cli/migrate latest              인덱스 있음 → EXPLAIN type: range
 *
 * 두 EXPLAIN 과 실측 시간을 docs/benchmarks.md 에 적는다.
 * "인덱스를 걸었더니 빨라졌다"가 아니라 숫자를 남기는 것이 목적이다.
 */
class Migration_Add_dispatch_poll_index extends CI_Migration
{
	public function up()
	{
		$this->db->query('CREATE INDEX ix_poll ON dispatch_outbox (status, next_retry_at, id)');
	}

	public function down()
	{
		$this->db->query('DROP INDEX ix_poll ON dispatch_outbox');
	}
}
