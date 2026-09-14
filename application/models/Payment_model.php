<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\GaClientId;
use App\Payment\CoinProduct;
use App\Payment\PaymentStateMachine;
use App\Payment\PaymentStatus;

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

	/**
	 * 조회 3곳이 같은 컬럼을 본다.
	 *
	 * HEX() 는 대문자, bin2hex() 는 소문자다. 같은 결제가 경로마다 다른
	 * 문자열로 나가면 호출자가 둘을 다른 것으로 센다 — 여기서 맞춘다.
	 */
	const SELECT_COLUMNS = 'SELECT id, LOWER(HEX(payment_uid)) AS uid_hex, user_id, status,
	                               amount_minor, currency, idempotency_key, captured_at
	                          FROM '.self::TABLE;

	/**
	 * 결제 행을 만든다. 같은 idempotency_key 면 만들지 않는다.
	 *
	 * 중복 판정은 `INSERT IGNORE` 의 affected_rows 로 하고, 기존 행은
	 * **그 뒤에** 읽는다(`findByIdempotencyKey`). 미리 SELECT 해서 막으면
	 * 동시 요청 둘이 다 통과한다 — Conversion_model 주석과 같은 규칙이다.
	 *
	 * @param array $p user_id · amount_minor · currency · idempotency_key · product
	 * @return array [id, uid_hex, duplicated]
	 */
	public function createIfAbsent(array $p)
	{
		$uidHex = bin2hex(tp_uuid7());
		$now    = tp_now_utc();

		$this->db->trans_begin();

		$this->db->query(
			'INSERT IGNORE INTO '.self::TABLE.' (
				payment_uid, user_id, pg, channel, status,
				amount_minor, currency, idempotency_key, created_at
			) VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?)',
			array(
				$uidHex,
				(int) $p['user_id'],

				// PG 는 스텁이다. 실제 연동이 아니라는 사실을 행마다 남긴다
				// — 나중에 진짜 PG 가 붙어도 옛 행을 구분할 수 있게 → ADR-008
				'stub',
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

		$this->db->trans_commit();

		return array('id' => $id, 'uid_hex' => $uidHex, 'duplicated' => FALSE);
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
	 * @param array  $payment findByUid() 의 결과
	 * @param string $to      전이 목적지
	 * @param mixed  $raw     payment_events.raw_payload. 웹훅 **원본 문자열**을 그대로 넘긴다
	 * @return array [applied, status, from, lot_id, conversion_uid, error]
	 */
	public function applyEvent(array $payment, $to, $raw)
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

			$this->appendEvent($paymentId, $current, $current, 'webhook', $raw, $now);
			$this->db->trans_commit();

			return self::result(FALSE, $current, $current, NULL);
		}

		$this->appendEvent($paymentId, $before, $to, 'webhook', $raw, $now);

		$effects = ($to === PaymentStatus::CAPTURED)
			? $this->onCaptured($payment, $now)
			: array('lot_id' => NULL, 'conversion_uid' => NULL);

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
	 * 결제 전환을 기록하고 아웃박스에 적재한다.
	 *
	 * **conversions.user_id 가 이 경로에서 처음 채워진다.** Conversion.php
	 * 주석이 "/signup·/purchase 가 붙을 때 함께 채워야 할 자리" 라고
	 * 표시해 둔 자리가 여기다.
	 *
	 * `visit_id` 는 users.signup_visit_id 를 경유한다. 즉 **가입 접점에
	 * 귀속되지 last-touch 가 아니다** — payments 에 visit_id 가 없어서다
	 * (계획 11장 ④). 결제 직전 클릭한 광고가 아니라 가입시킨 광고가
	 * 매출을 가져간다. 이건 선택이 아니라 스키마의 한계이고,
	 * C-2 의 "흡수된 방문자2 의 결제는 어디로 귀속되는가" 는 이 구조로는
	 * 끝까지 못 간다.
	 *
	 * @return string|null conversion uid hex. 중복(uq_dedup)이면 NULL
	 */
	private function recordConversion(array $payment, $amount, $currency, $now)
	{
		$this->load->model('conversion_model');
		$this->load->library('channels');

		$ctx = $this->attribution((int) $payment['user_id']);

		$result = $this->conversion_model->createWithOutbox(array(
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
	private function attribution($userId)
	{
		$row = $this->db
			->query(
				'SELECT LOWER(HEX(u.user_uid)) AS user_uid_hex,
				        u.signup_visit_id,
				        LOWER(HEX(v.visit_uid)) AS visit_uid_hex
				   FROM users u
				   LEFT JOIN visits v ON v.id = u.signup_visit_id
				  WHERE u.id = ?
				  LIMIT 1',
				array((int) $userId)
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
			log_message('info', 'payment: 가입 방문이 없어 client_id 가 빈다. user_id='.(int) $userId);
		}

		return array(
			'visit_id'     => $row->signup_visit_id === NULL ? NULL : (int) $row->signup_visit_id,
			'user_uid_hex' => (string) $row->user_uid_hex,
			'client_id'    => $clientId,
		);
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
