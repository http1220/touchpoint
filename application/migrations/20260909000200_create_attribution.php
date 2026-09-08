<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 어트리뷰션 — 방문과 터치포인트. 보존 3개월.
 *
 * 이 두 테이블만 보존기간이 3개월이다(통신비밀보호법, 웹사이트 방문기록).
 * 나머지 결제·회원은 5년이다. 20배 차이 나는 데이터를 한 테이블에 두면
 * 파기가 불가능해진다 — 여기서 테이블을 나눈 이유는 성능이 아니라 법이다.
 * → docs/data-model.md 7장
 *
 * FK 규칙(이 프로젝트 전체에 적용):
 *   부모와 자식의 보존기간이 같으면 FK 를 건다.
 *   다르면 걸지 않는다 — 부모를 파기할 때 FK 가 파기를 막거나,
 *   CASCADE 로 남겨야 할 자식까지 지워버린다.
 */
class Migration_Create_attribution extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS visits (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				visit_uid     BINARY(16)   NOT NULL COMMENT "UUIDv7",
				first_seen_at DATETIME(3)  NOT NULL COMMENT "UTC",
				landing_path  VARCHAR(512) NOT NULL,
				referrer      VARCHAR(512)     NULL,
				ua_hash       VARBINARY(32)    NULL,
				ip_hash       VARBINARY(32)    NULL COMMENT "원본 저장 안 함",
				country       CHAR(2)          NULL COMMENT "ISO 3166-1",
				lang          CHAR(2)          NULL COMMENT "ISO 639-1",
				PRIMARY KEY (id),
				UNIQUE KEY uq_visit_uid (visit_uid),
				KEY ix_purge (first_seen_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * UNIQUE (visit_id, position) 이 어트리뷰션 규칙을 스키마로 못 박는다.
		 *   first  — 한 번 만들어지면 갱신되지 않는다(INSERT IGNORE)
		 *   last   — 새 유입마다 갱신된다(UPSERT)
		 * 규칙 자체는 src/Attribution/TouchpointResolver.php 에 있고,
		 * 여기 제약은 그 규칙이 코드 버그로 깨졌을 때의 마지막 방어선이다.
		 *
		 * FK 는 건다 — visits 와 보존기간이 같다(3개월).
		 * CASCADE 라서 파기 배치가 visits 한 줄만 지우면 된다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS touchpoints (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				visit_id     BIGINT UNSIGNED NOT NULL,
				position     ENUM("first","last") NOT NULL,
				pid          VARCHAR(64)  NULL COMMENT "매체·파트너",
				subpid       VARCHAR(128) NULL COMMENT "캠페인·소재",
				channel      VARCHAR(32)  NULL COMMENT "search|display|social",
				utm_source   VARCHAR(128) NULL,
				utm_medium   VARCHAR(128) NULL,
				utm_campaign VARCHAR(128) NULL,
				utm_content  VARCHAR(128) NULL,
				utm_term     VARCHAR(128) NULL,
				gclid        VARCHAR(255) NULL,
				fbclid       VARCHAR(255) NULL,
				occurred_at  DATETIME(3)  NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_visit_position (visit_id, position),
				KEY ix_purge (occurred_at),
				CONSTRAINT fk_touchpoints_visit FOREIGN KEY (visit_id)
					REFERENCES visits (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * 방문 ↔ 회원 연결. visit_id 에만 FK 를 건다.
		 * user_id 에는 걸지 않는다 — users 는 5년, visits 는 3개월이라
		 * 보존기간이 다르다. 방문이 파기되면 이 연결도 의미가 없으므로 CASCADE.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS identities (
				visit_id  BIGINT UNSIGNED NOT NULL,
				user_id   BIGINT UNSIGNED NOT NULL,
				linked_at DATETIME(3) NOT NULL,
				PRIMARY KEY (visit_id, user_id),
				KEY ix_user (user_id),
				CONSTRAINT fk_identities_visit FOREIGN KEY (visit_id)
					REFERENCES visits (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS identities');
		$this->db->query('DROP TABLE IF EXISTS touchpoints');
		$this->db->query('DROP TABLE IF EXISTS visits');
	}
}
