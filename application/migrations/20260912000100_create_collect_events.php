<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 수집 이벤트. 보존 3개월.
 *
 * `/collect` 가 받는 것을 담는다. 방문·접점과 달리 **한 방문 안에서
 * 여러 건이 쌓인다** — 페이지를 옮길 때마다 하나씩 온다.
 *
 * 왜 별도 테이블인가
 *
 *   `visits` 는 방문당 1행, `touchpoints` 는 방문당 최대 2행(first/last)이다.
 *   이벤트를 거기 얹으면 그 제약이 깨진다. 그리고 이벤트는 보존 3개월이라
 *   `visits` 와 생명주기가 같다 — 그래서 FK 를 걸고 CASCADE 로 같이 지운다.
 *   → docs/data-model.md 7장
 *
 * transport 를 남기는 이유
 *
 *   같은 이벤트를 fetch 로도 sendBeacon 으로도 보낼 수 있고, 둘은
 *   preflight 유무와 이탈 중 전송 보장이 다르다. **어느 경로로 들어온
 *   건이 더 많이 유실되는지**를 재려면 행마다 남아 있어야 한다.
 *   → docs/failure-scenarios.md B-3
 */
class Migration_Create_collect_events extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS collect_events (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				visit_id    BIGINT UNSIGNED NOT NULL,
				event       VARCHAR(32) NOT NULL COMMENT "page_view|impression|click|…",
				work_id     BIGINT UNSIGNED NULL COMMENT "FK 없음 — 콘텐츠는 영구, 이벤트는 3개월",
				transport   ENUM("fetch","beacon","server") NOT NULL DEFAULT "fetch",
				origin      VARCHAR(255) NULL COMMENT "요청의 Origin. CORS 실험 기록용",
				occurred_at DATETIME(3) NOT NULL COMMENT "클라이언트가 주장하는 시각",
				received_at DATETIME(3) NOT NULL COMMENT "서버가 받은 시각. 이쪽이 신뢰 기준",
				PRIMARY KEY (id),
				KEY ix_visit_time (visit_id, occurred_at),
				KEY ix_event_time (event, received_at),
				KEY ix_purge (received_at),
				CONSTRAINT fk_collect_visit FOREIGN KEY (visit_id)
					REFERENCES visits (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS collect_events');
	}
}
