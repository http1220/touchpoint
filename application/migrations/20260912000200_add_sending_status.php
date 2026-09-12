<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 아웃박스에 `sending` 상태와 좀비 회수용 컬럼을 더한다.
 *
 * 원래 상태는 넷이었다 — pending / sent / failed / dead.
 * 워커를 실제로 쓰려니 하나가 모자란다.
 *
 *   워커가 행을 집어 잠금을 풀고 외부 HTTP 를 부르는 사이,
 *   그 행은 **어떤 상태여야 하는가?**
 *
 * `pending` 으로 두면 다른 워커가 같은 행을 또 집는다.
 * `sent` 로 두면 실패해도 다시 못 보낸다.
 * 그래서 "집혔고 아직 결과가 없다" 를 나타내는 상태가 필요하다.
 *
 * 그리고 **워커가 전송 도중 죽는 경우**를 설계에 넣어야 한다. 넣지 않으면
 * 그 행은 영원히 `sending` 이고 아무도 다시 보내지 않는다 —
 * 조용히 사라지는 종류의 실패다. `claimed_at` 이 회수 기준이 된다.
 *
 * → docs/outbox-and-channels.md 6장
 */
class Migration_Add_sending_status extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			"ALTER TABLE dispatch_outbox
			 MODIFY COLUMN status ENUM('pending','sending','sent','failed','dead')
			 NOT NULL DEFAULT 'pending'"
		);

		$this->db->query(
			'ALTER TABLE dispatch_outbox
			 ADD COLUMN claimed_at DATETIME(3) NULL
			 COMMENT "워커가 집은 시각. 좀비 회수 기준" AFTER attempt'
		);

		/*
		 * 좀비 회수용 인덱스.
		 *
		 * (status, claimed_at) — 등호 뒤 범위. 폴링 인덱스와 같은 규칙이다.
		 * 회수 배치는 자주 돌지 않지만, 없으면 아웃박스가 커질수록
		 * 회수 한 번이 풀스캔이 된다.
		 */
		$this->db->query('CREATE INDEX ix_zombie ON dispatch_outbox (status, claimed_at)');
	}

	public function down()
	{
		$this->db->query('DROP INDEX ix_zombie ON dispatch_outbox');
		$this->db->query('ALTER TABLE dispatch_outbox DROP COLUMN claimed_at');

		// sending 으로 남아 있는 행을 먼저 되돌려야 ENUM 축소가 통과한다.
		$this->db->query("UPDATE dispatch_outbox SET status = 'pending' WHERE status = 'sending'");
		$this->db->query(
			"ALTER TABLE dispatch_outbox
			 MODIFY COLUMN status ENUM('pending','sent','failed','dead')
			 NOT NULL DEFAULT 'pending'"
		);
	}
}
