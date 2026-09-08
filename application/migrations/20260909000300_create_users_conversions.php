<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 회원과 전환. 보존 5년(전자상거래법).
 *
 * users 의 signup_* 컬럼이 이 모델의 핵심이다.
 * 원본 touchpoints 는 3개월 뒤 사라지는데 "가입 경로"는 회원 레코드에 남아야 한다.
 * 그래서 가입 시점에 스냅샷을 복사한다 — 비정규화지만 정당한 비정규화다.
 * 근거: 대상 플랫폼 개인정보처리방침의 수집 항목에 가입 경로가 명시돼 있다.
 * → docs/data-model.md 3장
 */
class Migration_Create_users_conversions extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS users (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_uid      BINARY(16)   NOT NULL,
				email         VARCHAR(255) NOT NULL,
				password_hash VARCHAR(255)     NULL COMMENT "Argon2id",
				provider      ENUM("local","google") NOT NULL DEFAULT "local",
				provider_uid  VARCHAR(128)     NULL,
				lang          CHAR(2)      NOT NULL DEFAULT "ko",
				country       CHAR(2)          NULL,

				signup_pid          VARCHAR(64)  NULL,
				signup_subpid       VARCHAR(128) NULL,
				signup_channel      VARCHAR(32)  NULL,
				signup_utm_source   VARCHAR(128) NULL,
				signup_utm_medium   VARCHAR(128) NULL,
				signup_utm_campaign VARCHAR(128) NULL,
				signup_visit_id     BIGINT UNSIGNED NULL COMMENT "FK 없음 — visits 는 3개월 뒤 사라진다",

				created_at    DATETIME(3)  NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_user_uid (user_uid),
				UNIQUE KEY uq_email (email),
				UNIQUE KEY uq_provider (provider, provider_uid)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * dedup_key 의 UNIQUE 가 중복 전환을 애초에 막는다.
		 *
		 * 앱에서 "이미 있나 SELECT 하고 없으면 INSERT" 로 막으면
		 * 두 요청이 동시에 들어왔을 때 둘 다 통과한다. 광고 전환은
		 * 사용자가 새로고침 한 번만 눌러도 그 상황이 만들어진다.
		 * 판정은 DB 가 한다. → src/Attribution/DedupKey.php
		 *
		 * user_id / visit_id 에 FK 를 걸지 않는 이유:
		 *   user_id  — 비회원 전환을 허용한다(NULL)
		 *   visit_id — visits 는 3개월, conversions 는 5년. 보존기간이 다르다
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS conversions (
				id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversion_uid BINARY(16)  NOT NULL,
				user_id        BIGINT UNSIGNED NULL COMMENT "비회원 전환 허용",
				visit_id       BIGINT UNSIGNED NULL,
				type           VARCHAR(32) NOT NULL COMMENT "signup|purchase|subscribe",
				value_minor    BIGINT          NULL COMMENT "정수 minor unit",
				currency       CHAR(3)         NULL COMMENT "ISO 4217",
				dedup_key      VARCHAR(64) NOT NULL,
				occurred_at    DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_dedup (dedup_key),
				UNIQUE KEY uq_conv_uid (conversion_uid),
				KEY ix_type_time (type, occurred_at),
				KEY ix_user (user_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS conversions');
		$this->db->query('DROP TABLE IF EXISTS users');
	}
}
