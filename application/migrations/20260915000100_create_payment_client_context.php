<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 결제를 시작한 브라우저의 맥락. 광고 매체 전송에만 쓴다.
 *
 * ── 왜 필요한가 ──
 *
 * Meta 전환 API 는 웹 이벤트에 페이지 URL 과 **원문** UA 를 요구하고,
 * 사람과 이어 붙이는 데 IP·`_fbp`·`_fbc` 를 쓴다. 결제 전환은 PG 웹훅에서
 * 발화하는데 **웹훅 요청에는 사용자 브라우저가 없다.** 브라우저가 있는
 * 마지막 순간이 `/purchase` 이므로 거기서 받아 둔다
 * → docs/decisions/ADR-005-channel-adapter.md 「결정」
 *
 * ── 왜 payments 컬럼이 아니라 별도 테이블인가 ──
 *
 * 보존기간이 다르다. payments 는 5년(전자상거래법), 이 값은 방문기록과
 * 같은 3개월이다. **보존기간이 다른 데이터를 한 테이블에 두면 파기가
 * 한 줄 DELETE 로 끝나지 않는다** — data-model.md 7장의 원칙 그대로.
 *
 * `visits` 는 UA·IP 를 해시로만 둔다. 그 결정은 바뀌지 않았다 — 어트리뷰션에
 * 원문이 필요 없다는 판단은 여전히 맞다. 원문은 **매체 전송이라는 다른
 * 목적**으로, 기한을 정해 여기에만 둔다.
 *
 * ── FK 를 건다 (CASCADE) ──
 *
 * payments_visit 과 반대 판단이다. 거기는 부모(visits)가 먼저 사라져서
 * FK 가 파기를 막았다. 여기는 **자식(이 테이블)이 먼저 사라지고** 부모
 * (payments)가 5년 남으므로 FK 가 파기를 막지 않는다.
 */
class Migration_Create_payment_client_context extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS payment_client_context (
				payment_id        BIGINT UNSIGNED NOT NULL,
				client_user_agent VARCHAR(512)  NULL COMMENT "원문. Meta 규격이 해시를 금지한다",
				client_ip_address VARCHAR(45)   NULL COMMENT "원문. IPv6 최대 45자",
				event_source_url  VARCHAR(1024) NULL COMMENT "결제를 시작한 페이지",
				fbp               VARCHAR(255)  NULL COMMENT "_fbp 쿠키",
				fbc               VARCHAR(255)  NULL COMMENT "_fbc 쿠키",
				captured_at       DATETIME(3)   NOT NULL COMMENT "UTC",
				PRIMARY KEY (payment_id),
				KEY ix_purge (captured_at),
				CONSTRAINT fk_client_context_payment FOREIGN KEY (payment_id)
					REFERENCES payments (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS payment_client_context');
	}
}
