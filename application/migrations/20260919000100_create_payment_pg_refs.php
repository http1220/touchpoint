<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PG 가 붙인 번호. 이니시스 tid · 페이팔 order·capture.
 *
 * ── 왜 필요한가 ──
 *
 * 스텁 PG 는 우리 payment_uid 로만 말했다. 실제 PG 는 **자기 번호**로 말한다.
 * 이니시스 환불(INIAPI)은 승인 tid 를 요구하고, 페이팔 환불 웹훅은 우리 uid 가
 * 아니라 capture ID 를 가리킬 수 있다. 이 번호를 잃으면 환불할 방법이 없다
 * → docs/plan-multi-pg.md B7
 *
 * ── 왜 payments 컬럼이 아니라 테이블인가 ──
 *
 * PG 마다 번호의 개수가 다르다. 이니시스는 tid 하나, 페이팔은 order 와 capture
 * 둘에 환불마다 refund ID 가 붙는다. 컬럼으로 두면 PG 가 늘 때마다 payments 를
 * 고쳐야 하고, payments 는 이미 "변경 금지" 로 적어 둔 표다(20260909000400).
 *
 * ── UNIQUE(pg, ref_type, ref_value) ──
 *
 * 같은 PG 번호가 두 결제에 붙으면 환불이 엉뚱한 결제를 되돌린다. 적재는
 * INSERT IGNORE 라 같은 번호를 다시 받아도(웹훅 재전송) 행이 늘지 않는다.
 *
 * ── FK 는 RESTRICT ──
 *
 * 결제는 지우지 않는다(5년 보존). 이 행도 결제와 같이 산다.
 */
class Migration_Create_payment_pg_refs extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS payment_pg_refs (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				payment_id BIGINT UNSIGNED NOT NULL,
				pg         VARCHAR(32)  NOT NULL COMMENT "payments.pg 와 같은 이름",
				ref_type   VARCHAR(16)  NOT NULL COMMENT "tid | order | capture | refund",
				ref_value  VARCHAR(64)  NOT NULL COMMENT "이니시스 tid 40byte · 페이팔 ID 17자",
				created_at DATETIME(3)  NOT NULL COMMENT "UTC",
				PRIMARY KEY (id),
				UNIQUE KEY uq_pg_ref (pg, ref_type, ref_value),
				KEY ix_payment (payment_id),
				CONSTRAINT fk_pg_refs_payment FOREIGN KEY (payment_id)
					REFERENCES payments (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS payment_pg_refs');
	}
}
