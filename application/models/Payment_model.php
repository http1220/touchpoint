<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\GaClientId;
use App\Payment\CoinProduct;
use App\Payment\PaymentStateMachine;
use App\Payment\PaymentStatus;
use App\Payment\RefundPolicy;

/**
 * 결제와 결제 이벤트.
 *
 * 이 모델의 존재 이유는 `applyEvent()` 한 메서드다 — **중복 수신과 순서
 * 역전을 무엇이 막는가** 에 대한 답이 거기 있고, 그 답은 애플리케이션이
 * 아니라 DB 다 → docs/plan-payment-webhook.md 3·4장
 *
 * ──────────────────────────────────────────────────────────
 * payment_events 를 읽는 규칙 — **`from_status <> to_status` 만 전이다.**
 *
 * 무시된 웹훅도 `from = to = 현재 상태` 로 append 한다(계획 12장 결정 2).
 * 무시를 로그에만 남기면 D-3 결과를 grep 으로 세야 하고, 그건 재현
 * 가능한 숫자가 아니다. 중복 시험의 `ignored 7` 은 **그 7행 자체가
 * 증거**다. 대가는 읽는 쪽에 있다 — 전이 횟수를 세는 쿼리가
 * `WHERE from_status <> to_status` 를 빠뜨리면 틀린 수를 센다.
 *   ✓ 전이 수:  WHERE from_status <> to_status OR from_status IS NULL
 *   ✓ 무시 수:  WHERE from_status = to_status
 * ──────────────────────────────────────────────────────────
 * **중첩 트랜잭션 주의** (CI3 vendor/.../DB_driver.php:970)
 *
 *     elseif ($this->_trans_depth > 1 OR $this->_trans_rollback())
 *     { $this->_trans_depth--; return TRUE; }   // 아무것도 되돌리지 않는다
 *
 * `applyEvent()` 의 트랜잭션 안에서 `Conversion_model::createWithOutbox()`
 * 를 부른다. 그쪽은 스스로 trans_begin/rollback 을 하는데, 깊이가 2 라
 * **안쪽 롤백은 아무것도 되돌리지 않고 TRUE 를 돌려준다.** 지금은
 * 안전하다 — 그 롤백 지점까지 createWithOutbox 가 INSERT 한 것이 없기
 * 때문이다(INSERT IGNORE 가 0행). 즉 **우연히 안전하다.** 그쪽에 앞선
 * 쓰기가 한 줄이라도 생기면 여기서 조용히 깨진다. 같은 경고를
 * Conversion_model 에도 적어 뒀다 — 한쪽만 보고 고치는 일을 막는다.
 */
class Payment_model extends CI_Model
{
	const TABLE = 'payments';
	const EVENTS = 'payment_events';

	/** `purchase:` + 32자 hex = 41자. conversions.dedup_key 는 VARCHAR(64) 다. */
	const DEDUP_PREFIX = 'purchase:';

	/** `refund:` + 32자 hex. 같은 결제의 환불은 한 번만 매체로 간다. */
	const REFUND_PREFIX = 'refund:';

	/**
	 * 조회 3곳이 같은 컬럼을 본다.
	 *
	 * HEX() 는 대문자, bin2hex() 는 소문자다. 같은 결제가 경로마다 다른
	 * 문자열로 나가면 호출자가 둘을 다른 것으로 센다 — 여기서 맞춘다.
	 */
	const SELECT_COLUMNS = 'SELECT id, LOWER(HEX(payment_uid)) AS uid_hex, user_id, visit_id, pg, status,
	                               amount_minor, currency, idempotency_key, captured_at
	                          FROM '.self::TABLE;

	/** PG 번호. 이니시스 tid · 페이팔 order·capture → 20260919000100 */
	const PG_REFS = 'payment_pg_refs';

	/**
	 * 결제 행을 만든다. 같은 idempotency_key 면 만들지 않는다.
	 *
	 * 중복 판정은 `INSERT IGNORE` 의 affected_rows 로 하고, 기존 행은
	 * **그 뒤에** 읽는다(`findByIdempotencyKey`). 미리 SELECT 해서 막으면
	 * 동시 요청 둘이 다 통과한다 — Conversion_model 주석과 같은 규칙이다.
	 *
	 * @param array $p user_id · amount_minor · currency · idempotency_key · product · pg
	 * @return array [id, uid_hex, duplicated]
	 */
	public function createIfAbsent(array $p)
	{
		$uidHex = bin2hex(tp_uuid7());
		$now    = tp_now_utc();

		$this->db->trans_begin();

		$this->db->query(
			'INSERT IGNORE INTO '.self::TABLE.' (
				payment_uid, user_id, visit_id, pg, channel, status,
				amount_minor, currency, idempotency_key, created_at
			) VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			array(
				$uidHex,
				(int) $p['user_id'],

				/*
				 * 결제 시점의 방문. 없으면 NULL.
				 *
				 * `users.signup_visit_id`(가입 접점)와 **다른 값**이다.
				 * 가입은 A 광고로 하고 석 달 뒤 B 광고를 보고 돌아와
				 * 결제할 수 있다. 둘 다 남겨 둬야 **어느 쪽으로 귀속할지
				 * 고를 수 있다** — 하나만 있으면 고르는 게 아니라
				 * 그것밖에 없는 것이다 → docs/failure-scenarios.md C-2
				 */
				isset($p['visit_id']) && $p['visit_id'] !== NULL ? (int) $p['visit_id'] : NULL,

				/*
				 * 어느 PG 로 결제하는가. 기본은 스텁이다.
				 *
				 * 처음엔 'stub' 을 박아 두고 "실제 연동이 아니라는 사실을 행마다
				 * 남긴다" 고 적었다(ADR-008). 이니시스가 붙으면서 호출자가 고른다 —
				 * 그 주석의 의도는 그대로다: 옛 행은 여전히 stub 이라 구분된다
				 * → docs/plan-multi-pg.md B7
				 */
				isset($p['pg']) && $p['pg'] !== '' ? (string) $p['pg'] : 'stub',
				'web',
				PaymentStatus::CREATED,
				(int) $p['amount_minor'],
				strtoupper((string) $p['currency']),
				(string) $p['idempotency_key'],
				$now,
			)
		);

		$affected = (int) $this->db->affected_rows();
		$id       = (int) $this->db->insert_id();

		if ($affected === 0 OR $id === 0)
		{
			// uq_idem 에 걸렸다. 같은 결제 시도가 이미 있다.
			$this->db->trans_rollback();

			return array('id' => NULL, 'uid_hex' => NULL, 'duplicated' => TRUE);
		}

		/*
		 * 최초 이벤트는 from_status = NULL 이다.
		 *
		 * created 는 전이의 목적지가 아니라 시작점이라 "어디서 왔는가" 가
		 * 없다. 읽는 쪽이 전이를 셀 때 이 행을 빠뜨리지 않도록
		 * `from_status IS NULL` 을 함께 봐야 한다(클래스 주석).
		 *
		 * raw_payload 에 상품 코드를 남긴다. payments 에는 상품을 적을 칸이
		 * 없고(스키마 고정), 감사 추적에서 "무엇을 샀는가" 를 답할 수 있는
		 * 곳이 여기뿐이다.
		 */
		$this->appendEvent($id, NULL, PaymentStatus::CREATED, 'api', array(
			'product'         => isset($p['product']) ? (string) $p['product'] : NULL,
			'idempotency_key' => (string) $p['idempotency_key'],
			'amount_minor'    => (int) $p['amount_minor'],
			'currency'        => strtoupper((string) $p['currency']),
		), $now);

		/*
		 * 브라우저 맥락. 결제 행과 같은 트랜잭션이다 — 결제는 있는데 맥락이
		 * 없는 상태가 "브라우저가 없었다" 와 구분되지 않게 되는 것을 막는다.
		 * 전부 비었으면(서버 간 호출) 행을 만들지 않는다.
		 */
		$client = isset($p['client_context']) && is_array($p['client_context'])
			? array_filter($p['client_context'], static function ($v) { return $v !== NULL && $v !== ''; })
			: array();

		if ($client !== array())
		{
			$this->db->query(
				'INSERT INTO payment_client_context (
					payment_id, client_user_agent, client_ip_address, event_source_url, fbp, fbc, captured_at
				) VALUES (?, ?, ?, ?, ?, ?, ?)',
				array(
					$id,
					$client['client_user_agent'] ?? NULL,
					$client['client_ip_address'] ?? NULL,
					$client['event_source_url'] ?? NULL,
					$client['fbp'] ?? NULL,
					$client['fbc'] ?? NULL,
					$now,
				)
			);
		}

		$this->db->trans_commit();

		return array('id' => $id, 'uid_hex' => $uidHex, 'duplicated' => FALSE);
	}

	/**
	 * `/purchase` 가 남긴 브라우저 맥락. 없거나 파기됐으면 빈 배열.
	 *
	 * 키 이름을 Meta 규격 그대로 둔다 — payload 에 그대로 합쳐지고,
	 * 어댑터가 같은 이름으로 읽는다. 없는 값은 키째 뺀다.
	 *
	 * @return array<string, string>
	 */
	private function clientContext($paymentId)
	{
		$row = $this->db
			->query(
				'SELECT client_user_agent, client_ip_address, event_source_url, fbp, fbc
				   FROM payment_client_context WHERE payment_id = ? LIMIT 1',
				array((int) $paymentId)
			)
			->row_array();

		if ( ! $row)
		{
			return array();
		}

		return array_filter($row, static function ($v) { return $v !== NULL && $v !== ''; });
	}

	/**
	 * payment_uid 로 결제를 찾는다.
	 *
	 * 복제본이 아니라 $this->db(프라이머리)로 읽는다. 웹훅은 /purchase 가
	 * 커밋한 **직후에** 올 수 있고, 복제본에서 아직 안 보이면 멀쩡한 결제가
	 * 404 로 떨어진다 → ADR-007
	 *
	 * @return array|null [id, uid_hex, user_id, status, amount_minor, currency, captured_at]
	 */
	public function findByUid($uidHex)
	{
		if ( ! self::isUidHex($uidHex))
		{
			return NULL;
		}

		return self::hydrate(
			$this->db
				->query(self::SELECT_COLUMNS.' WHERE payment_uid = UNHEX(?) LIMIT 1', array($uidHex))
				->row()
		);
	}

	/**
	 * `createIfAbsent()` 가 duplicated 를 돌려준 **뒤에만** 부른다.
	 *
	 * 먼저 불러서 중복을 거르는 용도로 쓰면 안 된다 — 그 순서로 두면
	 * 동시 요청 둘이 다 SELECT 를 통과한다. 판정은 uq_idem 이 한다.
	 *
	 * @return array|null
	 */
	public function findByIdempotencyKey($key)
	{
		return self::hydrate(
			$this->db
				->query(self::SELECT_COLUMNS.' WHERE idempotency_key = ? LIMIT 1', array((string) $key))
				->row()
		);
	}

	/**
	 * user_uid → users.id.
	 *
	 * **User_model 이 없어서 여기 있다.** `/signup` 이 미구현이라 users 를
	 * 읽는 곳이 결제 경로뿐이다(계획 11장 ③). 가입이 붙으면 이 메서드는
	 * User_model 로 옮겨야 한다 — 여기 남겨 두면 "회원 조회는 결제 모델에
	 * 있다" 는 규칙이 생겨 버린다.
	 *
	 * payments.user_id 는 NOT NULL + fk_payments_user 라 없는 회원으로는
	 * INSERT 자체가 막힌다. FK 오류로 500 을 내는 대신 여기서 404 로
	 * 돌려보내려고 먼저 조회한다 → 계획 12장 결정 4
	 *
	 * @return int|null
	 */
	public function findUserIdByUid($uidHex)
	{
		if ( ! self::isUidHex($uidHex))
		{
			return NULL;
		}

		$row = $this->db
			->query('SELECT id FROM users WHERE user_uid = UNHEX(?) LIMIT 1', array($uidHex))
			->row();

		return $row ? (int) $row->id : NULL;
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 웹훅 하나를 적용한다. **CAS + 이벤트 + 부수효과가 한 트랜잭션이다.**
	 *
	 *     BEGIN
	 *       UPDATE payments … WHERE id = ? AND status IN (허용 from)   ← 0행이면 끝
	 *       INSERT INTO payment_events (…)
	 *       ── to='captured' 일 때만 ──
	 *       INSERT INTO coin_lots (…)
	 *       Conversion_model::createWithOutbox(…)
	 *     COMMIT
	 *
	 * **판정과 쓰기가 한 문장이라 틈이 없다.** `SELECT status` 로 읽고
	 * PHP 에서 판정한 뒤 UPDATE 하면 동시 여덟이 전부 같은 값을 읽어
	 * 여덟 다 통과한다. conversions 는 uq_dedup 이 막아 1행으로 끝나지만
	 * **coin_lots 에는 UNIQUE 가 없어 코인이 여덟 배로 지급된다.**
	 * 증상이 "결제 오류" 가 아니라 "재화 초과 지급" 이라 결제 로그를
	 * 봐도 안 보인다 → 계획 3장. 그 경로는 대조군 스위치로만 탄다.
	 *
	 * ── 웹훅만 들어오는 문이 아니다 (09-19) ──
	 *
	 * 이니시스 카드 결제는 웹훅이 없다. 승인 결과를 우리가 **동기로** 받아
	 * 이 메서드에 넣는다(source='return'). CLI 환불은 source='admin'.
	 * 어느 문으로 들어와도 같은 CAS 를 탄다 — 중복·역전 방어를 PG 마다 다시
	 * 만들지 않는다 → docs/plan-multi-pg.md 2장
	 *
	 * @param array  $payment findByUid() 의 결과
	 * @param string $to      전이 목적지
	 * @param mixed  $raw     payment_events.raw_payload. 웹훅은 **원본 문자열**, 실PG 는 허용 목록을 거친 배열
	 * @param string $source  webhook | return | admin
	 * @param array  $pgRefs  PG 번호(ref_type => 값). **전이가 일어났을 때만** 같은 트랜잭션에 적재한다
	 * @return array [applied, status, from, lot_id, conversion_uid, error]
	 */
	public function applyEvent(array $payment, $to, $raw, $source = 'webhook', array $pgRefs = array())
	{
		$paymentId = (int) $payment['id'];
		$to        = (string) $to;
		$allowed   = (new PaymentStateMachine())->allowedFrom($to);
		$now       = tp_now_utc();

		$this->db->trans_begin();

		/*
		 * 현재 상태를 먼저 읽는다. **판정이 아니라 이벤트에 붙일 라벨이다.**
		 *
		 * 잠금 없는 스냅샷 읽기라 동시 요청 사이에서 낡을 수 있다. 그래도
		 * 되는 이유: 이 값은 전이에 성공했을 때 from_status 로만 쓰이고,
		 * 전이 성공은 아래 CAS 의 WHERE 가 보장한다 — 실제 from 은 반드시
		 * 허용 집합 안에 있었다.
		 *
		 * 여기서 `FOR UPDATE` 로 잠그고 싶은 유혹이 있는데, 그러면 잠금이
		 * 판정의 일부가 되어 **측정이 무엇을 잰 것인지 흐려진다** —
		 * "CAS 가 막은 것인가 잠금이 막은 것인가" 를 구분할 수 없다.
		 * 막는 것은 CAS 하나여야 한다 → 계획 8장
		 */
		$before = $this->statusOf($paymentId, FALSE);

		if ($before === NULL)
		{
			// 트랜잭션 사이에 사라졌다. 결제는 지우지 않으므로 정상 경로가 아니다.
			$this->db->trans_rollback();
			log_message('error', 'payment applyEvent: 트랜잭션 안에서 결제 행이 사라졌다. id='.$paymentId);

			return self::result(FALSE, NULL, NULL, 'payment-vanished');
		}

		/*
		 * 캡처보다 먼저 도착한 환불. **무시가 아니라 재시도 요청이다.**
		 *
		 * 여기서 무시(200)하면 PG 는 다시 보내지 않고, 뒤이어 온 captured 가
		 * 결제를 살려 둔다 — 돈은 돌려줬는데 코인·매출이 남는다.
		 * 기록(payment_events)도 남기지 않는다. 재전송마다 행이 쌓이고,
		 * 그 행들은 "일어나지 않은 전이" 다 → src/Payment/RefundPolicy
		 *
		 * $before 는 잠금 없는 스냅샷이라 낡을 수 있다. 낡은 쪽으로 틀리면
		 * (실제로는 방금 captured 가 됐다) 409 를 한 번 더 주고 다음 재전송에서
		 * 처리된다 — 잃는 것은 재전송 한 번이다.
		 */
		if (RefundPolicy::arrivedBeforeCapture($before, $to))
		{
			$this->db->trans_rollback();
			log_message('error', sprintf('payment refund: 캡처 전 환불 도착 — 재시도 요청. id=%d status=%s', $paymentId, $before));

			return self::result(FALSE, $before, $before, 'refund-before-capture');
		}

		$applied = tp_env_bool('PAYMENT_WEBHOOK_PRECHECK')
			? $this->transitionByPrecheck($paymentId, $before, $to, $allowed, $now)
			: $this->transitionByCas($paymentId, $to, $allowed, $now);

		if ( ! $applied)
		{
			/*
			 * 무시다 — 과거이거나, 같은 상태이거나, 이미 종결이다.
			 *
			 * 여기서만 `FOR UPDATE` 로 다시 읽는다. REPEATABLE READ 에서는
			 * 위의 스냅샷 읽기를 반복해도 같은(낡은) 값이 나오는데, 무시된
			 * 이유를 설명할 값은 **지금 커밋돼 있는 상태**다. 판정은 이미
			 * 끝났으므로 이 잠금이 결과를 바꾸지는 않는다.
			 *
			 * 응답의 status 도 이 값이다 — `{"result":"ignored","status":"captured"}`
			 */
			$current = $this->statusOf($paymentId, TRUE);

			$this->appendEvent($paymentId, $current, $current, $source, $raw, $now);
			$this->db->trans_commit();

			return self::result(FALSE, $current, $current, NULL);
		}

		$this->appendEvent($paymentId, $before, $to, $source, $raw, $now);

		/*
		 * PG 번호는 **전이와 같은 트랜잭션**이다.
		 *
		 * captured 가 커밋됐는데 tid 가 없으면 환불할 방법이 없다. 따로 적재하면
		 * 그 사이에 실패하는 창이 생긴다. 무시된 이벤트의 번호는 적재하지 않는다 —
		 * 장부에 반영되지 않은 승인의 번호가 이 결제에 붙으면, 그 번호로 환불할 때
		 * 엉뚱한 승인을 되돌린다.
		 */
		$this->addPgRefs($paymentId, isset($payment['pg']) ? (string) $payment['pg'] : 'stub', $pgRefs, $now);

		if ($to === PaymentStatus::CAPTURED)
		{
			$effects = $this->onCaptured($payment, $now);
		}
		elseif ($to === PaymentStatus::REFUNDED)
		{
			$effects = $this->onRefunded($payment, $now);
		}
		else
		{
			$effects = array('lot_id' => NULL, 'conversion_uid' => NULL);
		}

		/*
		 * 커밋 전에 트랜잭션 상태를 본다.
		 *
		 * db_debug 가 꺼져 있으면 쿼리 오류가 예외를 내지 않고 조용히
		 * 지나간다. 그대로 커밋하면 **"status 는 captured 인데 코인이 없다"**
		 * 가 만들어지고, 그건 사용자가 먼저 발견한다. 되돌릴 수 있는 쪽으로
		 * 넘어진다 — 롤백하면 PG 가 재전송하고 다시 기회가 온다.
		 */
		if ($this->db->trans_status() === FALSE)
		{
			$this->db->trans_rollback();
			log_message('error', sprintf(
				'payment applyEvent: 쿼리 실패로 롤백했다. id=%d %s → %s', $paymentId, $before, $to
			));

			return self::result(FALSE, $before, NULL, 'db-error');
		}

		$this->db->trans_commit();

		// array_merge 다. `+` 는 왼쪽 키를 남겨서 result() 의 NULL 이 이긴다.
		return array_merge(self::result(TRUE, $to, $before, NULL), $effects);
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 원자적 조건부 UPDATE. **이 프로젝트가 중복과 역전을 막는 방법이다.**
	 *
	 * 허용된 from 목록이 그대로 `IN (…)` 이 된다. 표를 한 칸 고치면 이
	 * 쿼리가 함께 바뀐다 → src/Payment/PaymentStateMachine
	 *
	 * affected_rows 를 그대로 믿어도 되는 이유: 전이는 status 값을 반드시
	 * **다른 값으로** 바꾼다(같은 상태로의 전이는 표에 없다). MySQL 이
	 * "값이 같아서 0" 을 돌려주는 경우가 생기지 않는다.
	 */
	private function transitionByCas($paymentId, $to, array $allowed, $now)
	{
		if ($allowed === array())
		{
			// 전이표에 없는 목적지. `IN ()` 은 MySQL 구문 오류라 쿼리를 만들지 않는다.
			return FALSE;
		}

		$this->db->query(
			'UPDATE '.self::TABLE.'
			    SET status = ?,
			        captured_at = IF(? = ?, ?, captured_at)
			  WHERE id = ?
			    AND status IN ('.implode(', ', array_fill(0, count($allowed), '?')).')',
			array_merge(
				array($to, $to, PaymentStatus::CAPTURED, $now, (int) $paymentId),
				$allowed
			)
		);

		return (int) $this->db->affected_rows() === 1;
	}

	/**
	 * **일부러 틀린 경로.** D-3 대조군이다 (.env 의 PAYMENT_WEBHOOK_PRECHECK).
	 *
	 * 먼저 읽고(잠금 없이) PHP 에서 판정한 다음 조건 없이 UPDATE 한다.
	 * 동시 여덟이 전부 `authorized` 를 읽으므로 여덟 다 통과하고,
	 * `onCaptured()` 가 여덟 번 돈다 → coin_lots 8행.
	 *
	 * **운영에서 켤 이유가 없다.** 끈 쪽을 돌려 봐야 켠 쪽이 얼마를
	 * 버는지 말할 수 있어서 남긴다 — OUTBOX_SKIP_LOCKED · NOOP_OUTCOME 과
	 * 같은 자리다 → 계획 12장 결정 3
	 *
	 * affected_rows 를 **보지 않는다.** 이미 captured 인 행에 captured 를
	 * 쓰면 MySQL 은 0을 돌려주는데, 그걸 "막혔다" 로 읽으면 대조군이
	 * 우연히 정상처럼 보인다. 틀린 코드는 틀린 그대로 재현돼야 한다.
	 */
	private function transitionByPrecheck($paymentId, $before, $to, array $allowed, $now)
	{
		if ( ! in_array($before, $allowed, TRUE))
		{
			return FALSE;
		}

		/*
		 * 검사와 쓰기 사이를 **일부러 벌린다.** (`PAYMENT_WEBHOOK_PRECHECK_DELAY_MS`)
		 *
		 * 처음 대조군을 돌렸을 때 **버그가 재현되지 않았다** — 8건이 같은
		 * 초에 도착했는데도 `applied 1 · ignored 7` 로 정상 경로와 똑같이
		 * 나왔다. 요청 한 건이 20~40ms 라 두 번째 요청이 SELECT 할 때쯤
		 * 첫 번째가 이미 커밋돼 있었고, 그래서 `captured` 를 읽고 스스로
		 * 물러난 것이다.
		 *
		 * **그게 check-then-act 버그의 진짜 성질이다.** 늘 터지지 않는다.
		 * 부하가 높거나 쿼리가 느려질 때만 창이 열리고, 그래서 운영에
		 * 살아남는다. 재현하려면 그 창을 손으로 벌려야 한다.
		 *
		 * 이 지연은 **대조군 전용**이다. CAS 경로에는 없다 — 거기는 창이
		 * 아무리 넓어도 `WHERE status IN (…)` 이 막기 때문이고, 그 차이를
		 * 보이는 것이 이 스위치의 목적이다.
		 */
		$delayMs = (int) (getenv('PAYMENT_WEBHOOK_PRECHECK_DELAY_MS') ?: 0);

		if ($delayMs > 0)
		{
			usleep(min($delayMs, 2000) * 1000);
		}

		$this->db->query(
			'UPDATE '.self::TABLE.'
			    SET status = ?,
			        captured_at = IF(? = ?, ?, captured_at)
			  WHERE id = ?',
			array($to, $to, PaymentStatus::CAPTURED, $now, (int) $paymentId)
		);

		return TRUE;
	}

	/**
	 * 전환과 코인. **`captured` CAS 가 1행을 바꾼 그 트랜잭션 안이다.**
	 *
	 * 갈라 두면 *"돈은 받았는데 코인이 없다"* 가 만들어지고, 그건 사용자가
	 * 먼저 발견한다 → 계획 7장
	 *
	 * @return array [lot_id, conversion_uid]
	 */
	private function onCaptured(array $payment, $now)
	{
		$userId   = (int) $payment['user_id'];
		$amount   = (int) $payment['amount_minor'];
		$currency = (string) $payment['currency'];

		$out = array('lot_id' => NULL, 'conversion_uid' => NULL);

		/*
		 * 몇 코인을 줄 것인가 — **우리가 기록한 금액**에서 되찾는다.
		 *
		 * payments 에 상품 코드 칸이 없다(20260909000400, 변경 금지).
		 * 웹훅 본문의 금액을 쓰지 않는 이유는 계획 6장 그대로다: 금액의
		 * 진실은 우리가 만든 결제 행이다.
		 *
		 * 대가가 있다 — **상품표에서 가격을 고치면 그 전에 만들어진
		 * 결제가 여기서 상품을 잃는다.** 가격을 바꿀 때는 새 코드를
		 * 추가하고 옛 줄을 남겨야 한다(CoinProductTest 가 금액 중복을 막는다).
		 * 대안은 payment_events 의 created 행에 적어 둔 product 를 읽는
		 * 것인데, 그러면 감사 로그가 지급 금액을 결정하게 된다. 지금은
		 * 기록과 판정을 섞지 않는 쪽을 택했다.
		 */
		$product = CoinProduct::findByPrice($amount, $currency);

		if ($product === NULL)
		{
			/*
			 * 코인을 못 준다. 그래도 전이와 전환은 남긴다 — 돈은 실제로
			 * 받았고, 그 사실을 지우면 대사(reconciliation)에서 사라진다.
			 * 지급은 사람이 손으로 메워야 하므로 error 로 남긴다.
			 */
			log_message('error', sprintf(
				'payment captured: 상품표에 없는 금액이라 코인을 지급하지 못했다. payment_id=%d %d %s',
				(int) $payment['id'], $amount, $currency
			));
		}
		else
		{
			$this->load->model('coin_model');
			$out['lot_id'] = $this->coin_model->grantPurchaseLot($userId, (int) $payment['id'], $product, $now);
		}

		$out['conversion_uid'] = $this->recordConversion($payment, $amount, $currency, $now);

		return $out;
	}

	/**
	 * refunded 로 전이한 직후의 부수 효과. applyEvent 트랜잭션 안이다.
	 *
	 *   ① 코인 회수 — 이 결제가 만든 lot 의 남은 코인을 0 으로. 이미 쓴 코인은
	 *      음수로 만들지 않고 불일치로 남긴다 → RefundPolicy ②
	 *   ② 매체 환불 — 원래 구매 전환을 가리키는 `refund` 전환을 적재한다.
	 *      같은 트랜잭션이라 "환불은 됐는데 매체엔 매출이 그대로" 가 구조적으로
	 *      생기지 않는다 → ADR-003
	 *
	 * @return array [lot_id, conversion_uid, coins_revoked, coins_spent, coins_already_revoked]
	 */
	private function onRefunded(array $payment, $now)
	{
		$this->load->model('coin_model');

		$coins = $this->coin_model->revokeByPayment((int) $payment['id'], $now);

		if ($coins['already'] > 0)
		{
			/*
			 * 이미 회수된 lot 이 있다. 정상 경로에서는 CAS 가 두 번째 refunded 전이를
			 * 막으므로 여기 오지 않는다. 오면 같은 작업이 두 번 실행된 것이다 —
			 * 회수 자체는 멱등하게 끝났지만 원인을 봐야 한다.
			 */
			log_message('error', sprintf('payment refund: 이미 회수된 lot %d개 — 환불 처리가 두 번 실행됐다. payment_id=%d', $coins['already'], (int) $payment['id']));
		}

		if ($coins['spent'] > 0)
		{
			/*
			 * 쓴 코인이 있는데 환불됐다. 약관의 청약철회 조건(이용 내역 없음)을
			 * PG 쪽 환불이 우회한 것이다. 거절할 수 없는 사실이라 적용은 하고,
			 * 사람이 판단할 수 있게 error 로 남긴다 → ADR-006 파생 규칙
			 */
			log_message('error', sprintf(
				'payment refund: 이미 사용한 코인이 있는 결제가 환불됐다. payment_id=%d 사용=%d 회수=%d',
				(int) $payment['id'], $coins['spent'], $coins['revoked']
			));
		}

		return array(
			'lot_id'         => NULL,
			'conversion_uid' => $this->recordRefund($payment, $now),
			'coins_revoked'  => $coins['revoked'],
			'coins_spent'    => $coins['spent'],
			'coins_already_revoked' => $coins['already'],
		);
	}

	/**
	 * 환불 전환을 적재한다.
	 *
	 * **새 전환 행이다.** 구매 전환을 지우거나 고치지 않는다 — 매체에 이미
	 * 보냈을 수 있고, 매체는 "취소" 를 별도 이벤트로 받는다(GA4 `refund`).
	 * 원래 구매는 `refund_of` 로 가리킨다.
	 *
	 * 보낼 매체는 `Channels::namesFor('refund')` 가 고른다. Meta 전환 API 에는
	 * 표준 환불 이벤트가 없어 빠진다 — 이 판단이 **매체 능력의 차이가 적재
	 * 쪽으로 새는 두 번째 자리**다(첫째는 브라우저 맥락) → ADR-005
	 *
	 * 원래 구매 전환이 없으면(대조군 스위치로 막혔거나 파기) 적재하지 않는다.
	 * 가리킬 대상이 없는 환불은 매체에서 아무것도 취소하지 못한다.
	 *
	 * @return string|null 환불 전환 uid hex
	 */
	private function recordRefund(array $payment, $now)
	{
		$this->load->model('conversion_model');
		$this->load->library('channels');

		$original = $this->conversion_model->findByDedupKey(self::DEDUP_PREFIX.$payment['uid_hex']);

		if ($original === NULL)
		{
			log_message('error', 'payment refund: 가리킬 구매 전환이 없어 매체 환불을 적재하지 않는다. payment='.$payment['uid_hex']);

			return NULL;
		}

		$ctx = $this->attribution((int) $payment['user_id'], isset($payment['visit_id']) ? $payment['visit_id'] : NULL);

		$result = $this->conversion_model->createWithOutbox(array(
			'user_id'     => (int) $payment['user_id'],
			'visit_id'    => $ctx['visit_id'],
			'type'        => 'refund',
			'value_minor' => (int) $payment['amount_minor'],
			'currency'    => (string) $payment['currency'],
			'dedup_key'   => self::REFUND_PREFIX.$payment['uid_hex'],
			'client_id'   => $ctx['client_id'],
			'user_uid'    => $ctx['user_uid_hex'],
			'occurred_at' => $now,
			'refund_of'   => $original['uid_hex'],
		), $this->channels->namesFor('refund'));

		return $result['duplicated'] ? NULL : $result['uid_hex'];
	}

	/**
	 * 결제 전환을 기록하고 아웃박스에 적재한다.
	 *
	 * **conversions.user_id 가 이 경로에서 처음 채워진다.** Conversion.php
	 * 주석이 "/signup·/purchase 가 붙을 때 함께 채워야 할 자리" 라고
	 * 표시해 둔 자리가 여기다.
	 *
	 * `visit_id` 는 **결제 시점의 방문**을 먼저 보고, 없으면 가입 접점으로
	 * 떨어진다 → attribution()
	 *
	 * 브라우저 맥락(UA·IP·URL·_fbp·_fbc)은 `/purchase` 가 남긴 것을 읽어
	 * payload 에 싣는다. 웹훅 요청에는 사용자 브라우저가 없다. 이미 3개월
	 * 파기로 사라졌으면 싣지 않고, 그때 Meta 어댑터는 보내지 않고 이유를
	 * 남긴다 → ADR-005 「결정」
	 *
	 * @return string|null conversion uid hex. 중복(uq_dedup)이면 NULL
	 */
	private function recordConversion(array $payment, $amount, $currency, $now)
	{
		$this->load->model('conversion_model');
		$this->load->library('channels');

		$ctx = $this->attribution((int) $payment['user_id'], isset($payment['visit_id']) ? $payment['visit_id'] : NULL);

		$result = $this->conversion_model->createWithOutbox($this->clientContext((int) $payment['id']) + array(
			'user_id'     => (int) $payment['user_id'],
			'visit_id'    => $ctx['visit_id'],
			'type'        => 'purchase',

			// 금액은 payments 에서 읽는다. 웹훅 본문이 아니다 → 계획 6장
			'value_minor' => $amount,
			'currency'    => $currency,
			'dedup_key'   => self::DEDUP_PREFIX.$payment['uid_hex'],
			'client_id'   => $ctx['client_id'],
			'user_uid'    => $ctx['user_uid_hex'],
			'occurred_at' => $now,
		), $this->channels->names());

		if ($result['duplicated'])
		{
			/*
			 * CAS 가 1행을 바꿨는데 전환이 중복이다.
			 *
			 * 정상 경로에서는 일어나지 않는다 — 이 자리에 오는 것은 결제당
			 * 한 번뿐이기 때문이다. 그러니 이게 찍히면 둘 중 하나다:
			 * 대조군 스위치가 켜져 있거나(예상된 결과 — uq_dedup 이 두 번째를
			 * 막은 것이다), CAS 가 새는 것이거나. 후자면 버그다.
			 */
			log_message('error', 'payment captured: 전환이 중복으로 걸렸다. dedup_key='
				.self::DEDUP_PREFIX.$payment['uid_hex'].' precheck='.(tp_env_bool('PAYMENT_WEBHOOK_PRECHECK') ? 'on' : 'off'));

			return NULL;
		}

		return $result['uid_hex'];
	}

	/**
	 * 이 회원의 유입 귀속 재료.
	 *
	 * visits 는 3개월 뒤 사라진다(보존기간). LEFT JOIN 이라 방문이 이미
	 * 파기됐으면 visit_id 만 남고 uid 는 NULL 이다 — 그때는 client_id 를
	 * 만들 씨앗이 없다.
	 *
	 * @return array [visit_id, user_uid_hex, client_id]
	 */
	private function attribution($userId, $paymentVisitId = NULL)
	{
		/*
		 * **결제 시점의 방문을 우선한다. 없으면 가입 접점으로 떨어진다.**
		 *
		 * 둘은 다른 값이다 — 가입은 A 광고로 하고 석 달 뒤 B 광고를 보고
		 * 돌아와 결제할 수 있다. 정산에서 묻는 것은 대개 **결제를 일으킨
		 * 쪽**이므로 그쪽을 먼저 본다.
		 *
		 * 전에는 이 선택지가 아예 없었다. `payments` 에 방문을 적을 칸이
		 * 없어서 **무조건 가입 접점**이었고, 그건 first-touch 를 고른 게
		 * 아니라 **고를 수 없었던 것**이다 → 20260914000100 마이그레이션
		 *
		 * `COALESCE` 로 한 쿼리에 담는다. 두 번 읽으면 그 사이에 파기
		 * 배치가 도는 창이 생긴다 — visits 는 3개월 뒤 사라진다.
		 */
		$row = $this->db
			->query(
				'SELECT LOWER(HEX(u.user_uid)) AS user_uid_hex,
				        COALESCE(?, u.signup_visit_id) AS visit_id,
				        LOWER(HEX(v.visit_uid))        AS visit_uid_hex
				   FROM users u
				   LEFT JOIN visits v ON v.id = COALESCE(?, u.signup_visit_id)
				  WHERE u.id = ?
				  LIMIT 1',
				array(
					$paymentVisitId === NULL ? NULL : (int) $paymentVisitId,
					$paymentVisitId === NULL ? NULL : (int) $paymentVisitId,
					(int) $userId,
				)
			)
			->row();

		if ($row === NULL)
		{
			// FK 가 있으므로 결제가 있는데 회원이 없을 수는 없다.
			log_message('error', 'payment: 결제의 회원을 찾지 못했다. user_id='.(int) $userId);

			return array('visit_id' => NULL, 'user_uid_hex' => '', 'client_id' => '');
		}

		$visitUid = $row->visit_uid_hex === NULL ? '' : (string) $row->visit_uid_hex;

		/*
		 * client_id 대체값.
		 *
		 * 웹훅은 서버 간 호출이라 _ga 쿠키가 없다. 방문 식별자로 형식만
		 * 맞춘 값을 만든다 — **브라우저 세션과는 이어지지 않아 GA4 에서
		 * 신규 사용자로 잡히지만** 전환 건수는 남는다. Conversion.php 의
		 * 같은 분기와 규칙을 맞춘다.
		 */
		$clientId = $visitUid === ''
			? ''
			: GaClientId::fallback($visitUid, tp_uuid7_unix($visitUid));

		if ($clientId === '')
		{
			log_message('info', 'payment: 귀속할 방문이 없어 client_id 가 빈다. user_id='.(int) $userId);
		}

		return array(
			'visit_id'     => $row->visit_id === NULL ? NULL : (int) $row->visit_id,
			'user_uid_hex' => (string) $row->user_uid_hex,
			'client_id'    => $clientId,
		);
	}

	/**
	 * 이 결제의 이벤트, 오래된 것부터. 결제 결과 화면(/pay/result)이 쓴다.
	 *
	 * raw_payload 는 싣지 않는다. 허용 목록을 거쳤어도 화면에 뿌릴 값은 아니다.
	 * 프라이머리에서 읽는다 — 복귀 직후의 결과 화면이라 방금 쓴 것을 읽는다 → ADR-007
	 *
	 * @return list<array{from: string|null, to: string, source: string, created_at: string}>
	 */
	public function events($paymentId)
	{
		$rows = $this->db
			->query('SELECT from_status, to_status, source, created_at FROM '.self::EVENTS.' WHERE payment_id = ? ORDER BY id', array((int) $paymentId))
			->result();

		$out = array();

		foreach ($rows as $row)
		{
			$out[] = array(
				'from'       => $row->from_status === NULL ? NULL : (string) $row->from_status,
				'to'         => (string) $row->to_status,
				'source'     => (string) $row->source,
				'created_at' => (string) $row->created_at,
			);
		}

		return $out;
	}

	/**
	 * 이 결제에 붙은 PG 번호. ref_type => 값.
	 *
	 * 같은 종류가 여럿이면(페이팔 부분환불의 refund ID 등) 먼저 적재된 것이다.
	 * 전체 환불에 필요한 tid · capture 는 결제당 하나다.
	 *
	 * @return array<string, string>
	 */
	public function findPgRefs($paymentId)
	{
		$rows = $this->db
			->query('SELECT ref_type, ref_value FROM '.self::PG_REFS.' WHERE payment_id = ? ORDER BY id', array((int) $paymentId))
			->result();

		$out = array();

		foreach ($rows as $row)
		{
			if ( ! isset($out[$row->ref_type]))
			{
				$out[$row->ref_type] = (string) $row->ref_value;
			}
		}

		return $out;
	}

	/**
	 * INSERT IGNORE — 같은 번호를 다시 받아도(웹훅 재전송) 행이 늘지 않는다.
	 * uq_pg_ref 가 **다른 결제**의 번호와 겹쳐서 무시된 것이면 그건 사고다.
	 * 여기서는 판별하지 않고 호출자가 findPgRefs 로 본다.
	 */
	private function addPgRefs($paymentId, $pg, array $refs, $now)
	{
		foreach ($refs as $type => $value)
		{
			if ( ! is_string($value) OR $value === '')
			{
				continue;
			}

			$this->db->query(
				'INSERT IGNORE INTO '.self::PG_REFS.' (payment_id, pg, ref_type, ref_value, created_at) VALUES (?, ?, ?, ?, ?)',
				array((int) $paymentId, (string) $pg, (string) $type, $value, $now)
			);
		}
	}

	/** @param bool $forUpdate 잠금 읽기. 판정이 끝난 뒤에만 쓴다 */
	private function statusOf($paymentId, $forUpdate)
	{
		$row = $this->db
			->query(
				'SELECT status FROM '.self::TABLE.' WHERE id = ? LIMIT 1'.($forUpdate ? ' FOR UPDATE' : ''),
				array((int) $paymentId)
			)
			->row();

		return $row ? (string) $row->status : NULL;
	}

	/**
	 * append-only 감사 추적. UPDATE 하지 않는다.
	 *
	 * @param mixed $raw 웹훅 원본 문자열이면 그대로, 배열이면 인코딩해서
	 */
	private function appendEvent($paymentId, $from, $to, $source, $raw, $now)
	{
		if (is_array($raw))
		{
			$raw = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$this->db->query(
			'INSERT INTO '.self::EVENTS.' (
				payment_id, from_status, to_status, source, raw_payload, created_at
			) VALUES (?, ?, ?, ?, ?, ?)',
			array((int) $paymentId, $from, $to, $source, $raw === '' ? NULL : $raw, $now)
		);
	}

	// ────────────────────────────────────────────────────────

	private static function hydrate($row)
	{
		if ( ! $row)
		{
			return NULL;
		}

		return array(
			'id'              => (int) $row->id,
			'uid_hex'         => (string) $row->uid_hex,
			'user_id'         => (int) $row->user_id,
			'visit_id'        => $row->visit_id === NULL ? NULL : (int) $row->visit_id,
			'pg'              => (string) $row->pg,
			'status'          => (string) $row->status,
			'amount_minor'    => (int) $row->amount_minor,
			'currency'        => (string) $row->currency,
			'idempotency_key' => (string) $row->idempotency_key,
			'captured_at'     => $row->captured_at === NULL ? NULL : (string) $row->captured_at,
		);
	}

	private static function result($applied, $status, $from, $error)
	{
		return array(
			'applied'        => (bool) $applied,
			'status'         => $status,
			'from'           => $from,
			'lot_id'         => NULL,
			'conversion_uid' => NULL,
			'error'          => $error,
		);
	}

	private static function isUidHex($raw)
	{
		return is_string($raw) && preg_match('/\A[0-9a-f]{32}\z/', $raw) === 1;
	}
}
