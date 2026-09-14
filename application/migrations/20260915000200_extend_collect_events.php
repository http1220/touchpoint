<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 노출·클릭을 CTR 로 묶을 수 있게 collect_events 를 넓힌다.
 *
 * `/impression` · `/click` 라우트는 09-10 부터 있었지만 메서드가 없어 404 였다.
 * 붙이려고 보니 **짝을 맞출 열이 없었다** — 어느 자리(slot)의 노출인지,
 * 어느 날(stat_date) 기준인지. event·work_id 만으로는 "첫 화면 상단 배너의
 * 어제 CTR" 을 계산할 수 없다.
 *
 *   slot       배너 자리. 같은 작품도 자리마다 클릭률이 다르다
 *   stat_date  집계 기준일. 클릭은 **노출된 날**(링크의 sd)로 잡는다 → src/Collect/ClickRequest
 *   dedup_key  클릭 중복 제거. 새로고침·뒤로 가기로 GET 이 두 번 온다.
 *              UNIQUE 라 동시 요청 둘도 한 행만 남는다. 노출은 NULL(여럿 허용)
 *
 * 보존기간은 그대로 3개월이다(received_at 기준 파기).
 */
class Migration_Extend_collect_events extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'ALTER TABLE collect_events
			 ADD COLUMN slot      VARCHAR(32) NULL COMMENT "배너 자리 — CTR 은 자리별" AFTER work_id,
			 ADD COLUMN stat_date DATE        NULL COMMENT "집계 기준일(UTC). 클릭은 노출일(sd)" AFTER slot,
			 ADD COLUMN dedup_key CHAR(40)    NULL COMMENT "클릭 중복 제거. 노출은 NULL" AFTER stat_date,
			 ADD UNIQUE KEY uq_collect_dedup (dedup_key),
			 ADD KEY ix_ctr (stat_date, slot, event)'
		);
	}

	public function down()
	{
		$this->db->query(
			'ALTER TABLE collect_events
			 DROP INDEX ix_ctr, DROP INDEX uq_collect_dedup,
			 DROP COLUMN dedup_key, DROP COLUMN stat_date, DROP COLUMN slot'
		);
	}
}
