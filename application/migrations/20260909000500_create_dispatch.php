<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 매체 전송 아웃박스와 전송 로그.
 *
 * 전환이 생기면 매체에 알려야 한다. 그런데 전환을 기록하는 트랜잭션 안에서
 * 외부 HTTP 를 부르면, 매체가 느릴 때 결제 트랜잭션이 그만큼 길어지고
 * 매체가 죽으면 결제가 같이 실패한다.
 * 그래서 같은 트랜잭션에서는 "보낼 것"만 적고, 전송은 워커가 따로 한다.
 * → docs/decisions/ADR-003-mysql-outbox.md
 *
 * 이 마이그레이션은 폴링 인덱스(ix_poll)를 만들지 않는다.
 * 인덱스가 없을 때의 EXPLAIN 을 실제로 보기 위해서다 —
 * 인덱스는 20260909000700 에서 추가한다. 그 사이가 실험 구간이다.
 * → docs/benchmarks.md
 */
class Migration_Create_dispatch extends CI_Migration
{
	public function up()
	{
		/*
		 * UNIQUE (conversion_id, channel) — 채널당 적재를 1건으로.
		 * FOR UPDATE SKIP LOCKED        — 워커가 여럿이어도 처리를 1회로.
		 * 두 겹이다. 적재를 막는 것과 처리를 막는 것은 다른 문제다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS dispatch_outbox (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversion_id BIGINT UNSIGNED NOT NULL,
				channel       VARCHAR(24) NOT NULL COMMENT "ga4|meta 등 매체 코드",
				payload       JSON NOT NULL,
				status        ENUM("pending","sent","failed","dead") NOT NULL DEFAULT "pending",
				attempt       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				next_retry_at DATETIME(3) NOT NULL,
				last_error    VARCHAR(512) NULL,
				sent_at       DATETIME(3)  NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_conv_channel (conversion_id, channel),
				CONSTRAINT fk_outbox_conversion FOREIGN KEY (conversion_id)
					REFERENCES conversions (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * 전송 시도 계측. 구간을 나눠 적는다 —
		 * total_ms 하나만 남기면 느릴 때 어디가 느린지 알 수 없고,
		 * "매체가 느리다"와 "우리 DB 가 느리다"를 구분하지 못한다.
		 *
		 * FK 를 걸지 않는다. 전송할 때마다 쌓이는 계측 테이블이라
		 * 매 INSERT 의 참조 검사 비용이 계측 자체를 왜곡한다.
		 * 게다가 outbox 는 전송 완료 90일 뒤, 이 로그는 3개월 보존이라
		 * 어차피 생명주기가 다르다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS dispatch_log (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				outbox_id   BIGINT UNSIGNED NOT NULL,
				trace_id    CHAR(32) NULL COMMENT "엣지에서 이어받은 상관 ID",
				channel     VARCHAR(24) NOT NULL,
				attempt     SMALLINT UNSIGNED NOT NULL,
				http_status SMALLINT UNSIGNED NULL,
				parse_ms    INT NOT NULL,
				db_ms       INT NOT NULL,
				send_ms     INT NOT NULL,
				total_ms    INT NOT NULL,
				created_at  DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				KEY ix_channel_time (channel, created_at),
				KEY ix_outbox (outbox_id),
				KEY ix_purge (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS dispatch_log');
		$this->db->query('DROP TABLE IF EXISTS dispatch_outbox');
	}
}
