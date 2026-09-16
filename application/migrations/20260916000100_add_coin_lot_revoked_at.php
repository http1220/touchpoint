<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 코인 lot 에 "회수됨" 을 남긴다.
 *
 * ── 왜 ──
 *
 * 환불 회수는 `remaining` 을 0 으로 만든다. 그러면 **두 번째 회수는 "원래
 * 100 이었는데 남은 게 0 = 100 을 썼다"** 로 읽힌다. 실제로는 하나도 안 썼는데
 * "쓴 코인이 있는 결제가 환불됐다" 경보가 거짓으로 뜬다.
 *
 * 지금은 결제 상태의 조건부 UPDATE 가 두 번째 회수를 막아서 사고가 안 난다.
 * 즉 안전이 **DB 한 줄에만** 걸려 있었다. 같은 작업이 두 번 실행되는 사고
 * (토스 자동이체 중복 출금 · Santander 예약 이체 중복)는 바로 그 한 줄이 새는
 * 경우다 → docs/incidents/testable-cases.md 2장
 *
 * 회수 시각을 lot 에 남기면 회수 자체가 멱등해진다 — 두 번째 호출은
 * "이미 회수됨" 을 돌려준다.
 *
 * NULL 을 허용한다. 이 컬럼 이전에 회수된 lot 은 remaining 0 · revoked_at NULL
 * 로 남는다(구분 불가). 대사는 그 경우를 "회수 누락" 으로 세지 않는다
 * → src/Payment/LedgerReconciliation
 */
class Migration_Add_coin_lot_revoked_at extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'ALTER TABLE coin_lots
			 ADD COLUMN revoked_at DATETIME(3) NULL COMMENT "환불 회수 시각(UTC). 회수를 멱등하게" AFTER expires_at'
		);
	}

	public function down()
	{
		$this->db->query('ALTER TABLE coin_lots DROP COLUMN revoked_at');
	}
}
