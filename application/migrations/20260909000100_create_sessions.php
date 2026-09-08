<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 세션 테이블.
 *
 * CI3 의 database 세션 드라이버가 요구하는 스키마 그대로다.
 * 컬럼을 마음대로 바꾸면 드라이버가 못 읽는다.
 *
 * sess_match_ip 를 FALSE 로 뒀으므로 PK 는 id 하나다.
 * TRUE 였다면 (id, ip_address) 복합 PK 여야 한다 — 설정과 스키마가 짝이다.
 *
 * 그리고 이 테이블은 프라이머리에서만 읽힌다. 복제본으로 가면
 * 로그인 직후 세션이 없어서 로그인이 풀린다. → ADR-017 ④
 */
class Migration_Create_sessions extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS ci_sessions (
				id         VARCHAR(128) NOT NULL,
				ip_address VARCHAR(45)  NOT NULL,
				timestamp  INT UNSIGNED NOT NULL DEFAULT 0,
				data       BLOB         NOT NULL,
				PRIMARY KEY (id),
				KEY ix_timestamp (timestamp)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS ci_sessions');
	}
}
