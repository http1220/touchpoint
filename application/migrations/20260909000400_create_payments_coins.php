<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 결제와 코인 원장. 보존 5년.
 *
 * 코인을 users.coin_balance INT 하나로 두지 않는 이유는 만료다.
 * 대상 플랫폼 이용약관: 유료 코인 5년, 무료·이벤트 코인 1년.
 * 만료가 다른 재화를 한 숫자로 합치면 어느 코인이 언제 만료되는지 알 수 없다.
 * 이 규칙은 UI 어디에도 없고 약관에만 있다. → docs/data-model.md 4장
 */
class Migration_Create_payments_coins extends CI_Migration
{
	public function up()
	{
		/*
		 * idempotency_key 의 UNIQUE 가 이중 결제를 막는다.
		 * 사용자가 결제 버튼을 두 번 누르거나 PG 가 웹훅을 재전송해도
		 * 두 번째 INSERT 가 DB 에서 튕긴다.
		 *
		 * amount_minor 는 BIGINT 정수다. FLOAT 금지 —
		 * KRW·JPY 는 ISO 4217 소수 자릿수가 0 이라 100 을 곱하면 그 자체로 틀린다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS payments (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				payment_uid     BINARY(16)  NOT NULL,
				user_id         BIGINT UNSIGNED NOT NULL,
				pg              VARCHAR(32) NOT NULL DEFAULT "stub",
				channel         ENUM("web","ios","android") NOT NULL DEFAULT "web",
				status          VARCHAR(24) NOT NULL,
				amount_minor    BIGINT      NOT NULL COMMENT "정수 minor unit. FLOAT 금지",
				currency        CHAR(3)     NOT NULL COMMENT "ISO 4217",
				idempotency_key VARCHAR(64) NOT NULL,
				created_at      DATETIME(3) NOT NULL,
				captured_at     DATETIME(3)     NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_payment_uid (payment_uid),
				UNIQUE KEY uq_idem (idempotency_key),
				KEY ix_user_time (user_id, created_at),
				CONSTRAINT fk_payments_user FOREIGN KEY (user_id)
					REFERENCES users (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * append-only 감사 추적. UPDATE 하지 않는다.
		 *
		 * 결제 상태를 payments.status 한 칸으로만 관리하면
		 * "언제 누가 무엇을 보고 바꿨는가"가 남지 않는다.
		 * 분쟁이 생겼을 때 답할 수 없는 질문이 바로 그것이다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS payment_events (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				payment_id  BIGINT UNSIGNED NOT NULL,
				from_status VARCHAR(24) NULL,
				to_status   VARCHAR(24) NOT NULL,
				source      ENUM("api","webhook","admin") NOT NULL,
				raw_payload JSON NULL,
				created_at  DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				KEY ix_payment (payment_id, id),
				CONSTRAINT fk_payment_events_payment FOREIGN KEY (payment_id)
					REFERENCES payments (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		/*
		 * ix_spend_order 는 소진 순서를 그대로 옮긴 인덱스다.
		 *   (user_id, remaining, expires_at, kind)
		 * 만료 임박 → 무료 우선. 사용자에게 유리한 순서라 분쟁이 최소가 된다.
		 * 순서 규칙 자체는 src/Coin/LedgerService.php 에 있다.
		 */
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS coin_lots (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT UNSIGNED NOT NULL,
				kind       ENUM("paid","free") NOT NULL COMMENT "만료 규칙이 갈리는 축",
				source     VARCHAR(32) NOT NULL COMMENT "purchase|event|attendance",
				channel    ENUM("web","ios","android") NOT NULL DEFAULT "web",
				amount     INT NOT NULL,
				remaining  INT NOT NULL,
				payment_id BIGINT UNSIGNED NULL,
				granted_at DATETIME(3) NOT NULL,
				expires_at DATETIME(3) NOT NULL COMMENT "paid=+5y, free=+1y",
				PRIMARY KEY (id),
				KEY ix_spend_order (user_id, remaining, expires_at, kind),
				KEY ix_payment (payment_id),
				CONSTRAINT fk_coin_lots_user FOREIGN KEY (user_id)
					REFERENCES users (id),
				CONSTRAINT fk_coin_lots_payment FOREIGN KEY (payment_id)
					REFERENCES payments (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);

		$this->db->query(
			'CREATE TABLE IF NOT EXISTS coin_spends (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id    BIGINT UNSIGNED NOT NULL,
				lot_id     BIGINT UNSIGNED NOT NULL,
				amount     INT NOT NULL,
				episode_id BIGINT UNSIGNED NULL COMMENT "FK 없음 — 콘텐츠는 영구, 소비 이력은 5년",
				spent_at   DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				KEY ix_user_time (user_id, spent_at),
				KEY ix_lot (lot_id),
				CONSTRAINT fk_coin_spends_user FOREIGN KEY (user_id)
					REFERENCES users (id),
				CONSTRAINT fk_coin_spends_lot FOREIGN KEY (lot_id)
					REFERENCES coin_lots (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
		);
	}

	public function down()
	{
		$this->db->query('DROP TABLE IF EXISTS coin_spends');
		$this->db->query('DROP TABLE IF EXISTS coin_lots');
		$this->db->query('DROP TABLE IF EXISTS payment_events');
		$this->db->query('DROP TABLE IF EXISTS payments');
	}
}
