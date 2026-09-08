<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 콘텐츠 — 전환 대상이 되는 최소 집합.
 *
 * 웹툰 플랫폼을 만드는 것이 아니다. 결제와 열람 권한이 성립하는 데
 * 꼭 필요한 것만 둔다. 조회수·별점 컬럼이 없는 이유는 게을러서가 아니라
 * 조사한 두 플랫폼이 모두 조회수를 공개하지 않기 때문이다.
 * → docs/data-model.md 6장
 */
class Migration_Create_content extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS works (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				work_uid        BINARY(16) NOT NULL,
				title           VARCHAR(255) NOT NULL,
				lang            CHAR(2) NOT NULL,
				age_rating_code VARCHAR(16) NOT NULL COMMENT "enum. boolean 은 국가별 등급 대응 불가",
				status          ENUM("ongoing","finished","rest") NOT NULL,
				wait_free_hours SMALLINT UNSIGNED NULL COMMENT "기다리면 무료. 작품 속성",
				synopsis        TEXT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_work_uid (work_uid),
				KEY ix_lang_status (lang, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * 회차는 전역 ID(id)와 작품 내 순번(seq)을 둘 다 갖는다.
		 * 조사한 두 플랫폼이 모두 그렇게 되어 있었다 — 한쪽 축만으로는
		 * "이 작품의 3화"와 "이 회차"를 같은 방식으로 다룰 수 없다.
		 *
		 * published_at 은 DATETIME 이다. 표시용 문자열을 그대로 저장한 사례를
		 * 조사에서 봤는데, 그러면 정렬도 비교도 불가능해진다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS episodes (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				work_id      BIGINT UNSIGNED NOT NULL,
				seq          INT NOT NULL COMMENT "작품 내 순번",
				subtitle     VARCHAR(255) NOT NULL,
				is_charged   TINYINT(1) NOT NULL DEFAULT 0,
				published_at DATETIME(3) NOT NULL COMMENT "UTC. 문자열 금지",
				PRIMARY KEY (id),
				UNIQUE KEY uq_work_seq (work_id, seq),
				CONSTRAINT fk_episodes_work FOREIGN KEY (work_id)
					REFERENCES works (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		// 연재 요일은 배열이다(주 2회 연재). 컬럼 하나에 넣을 수 없다.
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS work_publish_days (
				work_id     BIGINT UNSIGNED NOT NULL,
				day_of_week TINYINT UNSIGNED NOT NULL COMMENT "1=월 … 7=일",
				PRIMARY KEY (work_id, day_of_week),
				CONSTRAINT fk_publish_days_work FOREIGN KEY (work_id)
					REFERENCES works (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * 유료 회차 접근통제의 근거 테이블.
		 *
		 * 판정을 뷰 플래그로 하면 이미지 URL 을 직접 치는 순간 뚫린다.
		 * 웹툰에서 가장 비싼 버그다. 판정은 이미지 서빙 시점에 이 테이블로 한다.
		 * ix_check 가 그 판정 경로를 위한 인덱스다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS entitlements (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id       BIGINT UNSIGNED NOT NULL,
				episode_id    BIGINT UNSIGNED NOT NULL,
				kind          ENUM("own","rent") NOT NULL COMMENT "소장 / 대여",
				granted_at    DATETIME(3) NOT NULL,
				expires_at    DATETIME(3) NULL COMMENT "대여만 만료. 소장은 NULL",
				coin_spend_id BIGINT UNSIGNED NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_user_episode_kind (user_id, episode_id, kind),
				KEY ix_check (user_id, episode_id, expires_at),
				KEY ix_episode (episode_id),
				CONSTRAINT fk_entitlements_user FOREIGN KEY (user_id)
					REFERENCES users (id),
				CONSTRAINT fk_entitlements_episode FOREIGN KEY (episode_id)
					REFERENCES episodes (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * URL 슬러그와 ISO 코드는 같지 않다.
		 * 조사한 두 플랫폼에서 독립적으로 같은 문제가 나왔다 —
		 * kr 은 ISO 639-1 언어코드가 아니라 국가코드이고,
		 * 중국어는 언어가 아니라 표기(Hans/Hant)로 갈린다.
		 * 매핑 테이블 없이 문자열을 그대로 쓰면 hreflang 이 전부 틀린다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS locales (
				slug      VARCHAR(8)  NOT NULL COMMENT "URL 슬러그",
				lang      CHAR(2)     NOT NULL COMMENT "ISO 639-1",
				script    VARCHAR(4)  NULL COMMENT "ISO 15924. Hans/Hant",
				region    CHAR(2)     NULL COMMENT "ISO 3166-1",
				bcp47     VARCHAR(20) NOT NULL COMMENT "hreflang 에 쓰는 값",
				is_active TINYINT(1)  NOT NULL DEFAULT 1,
				PRIMARY KEY (slug)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS locales');
		$this->db->query('DROP TABLE IF EXISTS entitlements');
		$this->db->query('DROP TABLE IF EXISTS work_publish_days');
		$this->db->query('DROP TABLE IF EXISTS episodes');
		$this->db->query('DROP TABLE IF EXISTS works');
	}
}
