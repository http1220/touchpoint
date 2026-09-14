<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Payment\CoinProduct;

/**
 * 코인 결제 시작. `app.<도메인>` 에서만 열린다.
 *
 *   POST /purchase   payments(status='created') 1행 + payment_events 1행
 *
 * CORS 가 없다. /conversion 과 다른 점이 여기다 — 이 경로는 브라우저의
 * 크로스오리진 요청이 아니라 **같은 오리진(app.)의 폼 제출이거나 서버 간
 * 호출**이다. CorsPolicy 를 붙이면 A-3·B-1 의 CORS 측정에 성격이 다른
 * 트래픽이 섞인다 → 계획 12장 결정 1 과 같은 이유
 *
 * ──────────────────────────────────────────────────────────
 * **인증이 없다.** 본문의 user_uid 를 그대로 믿는다.
 *
 * `/signup` 이 미구현이라 인증 기반 자체가 없다. 감수한 것이 아니라
 * **미구현**이다. 악용 범위는 이렇다 —
 *
 *   ✓ 누구나 임의의 user_uid 로 payments 행(status='created')을 만들 수 있다
 *   ✗ 그러나 전환도 코인도 만들 수 없다. 그 둘은 captured 웹훅에서만
 *     발화하고, 웹훅은 HMAC 서명을 요구한다
 *
 * 즉 **서명이 GA4 오염을 막는 실질 방어선**이다. 스텁 PG 라 실제 청구도
 * 없다 → 계획 12장 결정 5
 * ──────────────────────────────────────────────────────────
 *
 * 금액은 **서버 상품표와 대조한다**. 클라이언트가 금액을 정하면 그건
 * 입력 오류가 아니라 결제 조작이다 → src/Payment/CoinProduct
 */
class Purchase extends MY_Controller
{
	/** payments.idempotency_key 는 VARCHAR(64) 다. */
	const IDEM_MAX_LENGTH = 64;

	public function __construct()
	{
		parent::__construct();

		$this->requireHost('app');
		$this->load->library('visitor');   // 생성자에서 visit_model 을 함께 올린다
		$this->load->model('payment_model');
	}

	/**
	 * 결제를 일으킨 방문.
	 *
	 * **쿠키가 있을 때만 찾는다.** `Visitor::current()` 는 방문이 없으면
	 * 새로 만드는데, 여기서 만들면 `landing_path = /purchase` 인 유입 없는
	 * 방문이 생겨 어트리뷰션 보존율의 분모를 오염시킨다. Conversion.php 가
	 * 같은 이유로 같은 선택을 했다.
	 *
	 * 없으면 NULL 이고, 그때는 가입 접점으로 떨어진다
	 * → Payment_model::attribution()
	 */
	private function currentVisitId()
	{
		$name   = getenv('COOKIE_VID_NAME') ?: 'ab_vid';
		$cookie = $this->input->cookie($name, TRUE);

		if ( ! is_string($cookie) OR preg_match('/\A[0-9a-f]{32}\z/', $cookie) !== 1)
		{
			return NULL;
		}

		return $this->visit_model->findByUid($cookie);
	}

	public function index()
	{
		if (strtoupper($this->server('REQUEST_METHOD')) !== 'POST')
		{
			$this->problem(405, 'method-not-allowed', 'POST 만 받습니다.');
		}

		$body = $this->body();

		$product = CoinProduct::find($body['product'] ?? NULL);

		if ($product === NULL)
		{
			$this->problem(422, 'invalid-request',
				'product 는 '.implode(' | ', CoinProduct::codes()).' 중 하나여야 합니다.',
				array('field' => 'product'));
		}

		if ( ! $product->matches(self::integer($body['amount_minor'] ?? NULL), self::text($body['currency'] ?? NULL)))
		{
			/*
			 * 기대값을 응답에 담는다.
			 *
			 * 가격은 어차피 공개된 값이고, 담지 않으면 호출자가 422 를 받고도
			 * 무엇으로 고쳐야 할지 모른다. 반대로 담지 말아야 할 것은
			 * 서명·창 크기처럼 방어의 크기를 알려 주는 값이다.
			 */
			$this->problem(422, 'invalid-request',
				'amount_minor·currency 가 상품표와 다릅니다. 금액은 서버가 정합니다.',
				array(
					'field'                 => 'amount_minor',
					'expected_amount_minor' => $product->amountMinor,
					'expected_currency'     => $product->currency,
				));
		}

		$idempotencyKey = self::text($body['idempotency_key'] ?? NULL);

		if ($idempotencyKey === '')
		{
			$this->problem(422, 'invalid-request',
				'idempotency_key 는 필수입니다. 같은 결제 시도면 반드시 같은 값이어야 합니다.',
				array('field' => 'idempotency_key'));
		}

		/*
		 * 길이를 여기서 막는다. 컬럼이 VARCHAR(64) 라 넘치면 잘리고,
		 * 잘린 키끼리 겹치면 **서로 다른 결제가 같은 결제로 처리된다.**
		 * (stricton=TRUE 라 실제로는 MySQL 이 오류를 내지만, 그 오류는
		 *  db_debug 때문에 HTML 에러 화면으로 나간다 → 계획 11장 ②)
		 */
		if (strlen($idempotencyKey) > self::IDEM_MAX_LENGTH)
		{
			$this->problem(422, 'invalid-request',
				'idempotency_key 는 '.self::IDEM_MAX_LENGTH.'바이트를 넘을 수 없습니다.',
				array('field' => 'idempotency_key'));
		}

		$userId = $this->payment_model->findUserIdByUid(strtolower(self::text($body['user_uid'] ?? NULL)));

		if ($userId === NULL)
		{
			/*
			 * 404 다. 422 가 아니다 — 형식이 틀린 것과 그런 회원이 없는 것은
			 * 호출자가 할 일이 다르다(고쳐서 재요청 vs 가입부터).
			 * Conversion.php 의 visit-not-found 와 같은 판단이다.
			 */
			$this->problem(404, 'user-not-found', '그런 회원이 없습니다. cli/seed user 로 만드세요.');
		}

		$result = $this->payment_model->createIfAbsent(array(
			'user_id'         => $userId,
			'amount_minor'    => $product->amountMinor,
			'currency'        => $product->currency,
			'idempotency_key' => $idempotencyKey,
			'product'         => $product->code,

			// 결제를 일으킨 방문. 쿠키가 없으면 NULL 이고, 그때만 가입 접점으로 떨어진다.
			'visit_id'        => $this->currentVisitId(),
		));

		if ($result['duplicated'])
		{
			$this->respondDuplicate($idempotencyKey);

			return;
		}

		log_message('info', sprintf('purchase new uid=%s product=%s user=%d',
			$result['uid_hex'], $product->code, $userId));

		$this->json(201, array(
			'payment_uid' => $result['uid_hex'],
			'status'      => 'created',
			'duplicated'  => FALSE,
			'trace_id'    => $this->trace_id,
		));
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 같은 idempotency_key 로 다시 들어온 요청.
	 *
	 * **200 이다.** 호출자가 받아야 할 것은 "이미 처리됐다 + 그때의
	 * payment_uid" 이고, 그것만 있으면 재시도 루프를 끝낼 수 있다.
	 * 사용자가 결제 버튼을 두 번 누르는 것은 정상 동작이다 → api-spec 4장
	 *
	 * 현재 status 를 함께 준다. 첫 요청 뒤 웹훅이 이미 왔을 수 있어서
	 * 'created' 라고 단정하면 틀린 값을 주게 된다.
	 */
	private function respondDuplicate($idempotencyKey)
	{
		$existing = $this->payment_model->findByIdempotencyKey($idempotencyKey);

		if ($existing === NULL)
		{
			/*
			 * UNIQUE 에는 걸렸는데 행이 없다.
			 *
			 * INSERT IGNORE 가 0 을 돌려줬다는 것은 충돌한 행이 커밋돼
			 * 있었다는 뜻이다(커밋 전이면 잠금에서 기다린다). 그러고도 못
			 * 찾으면 걸린 것이 uq_idem 이 아니라 uq_payment_uid 라는 말이고,
			 * 그건 UUIDv7 이 겹쳤다는 뜻이라 버그다.
			 * Conversion.php 와 같은 자리, 같은 판단이다.
			 */
			log_message('error', 'purchase: 중복 판정인데 기존 결제를 못 찾았다. key='.$idempotencyKey);
			$this->problem(409, 'payment-conflict', '중복으로 판정됐지만 기존 결제를 찾지 못했습니다.');
		}

		log_message('info', 'purchase dup uid='.$existing['uid_hex'].' key='.$idempotencyKey);

		$this->json(200, array(
			'payment_uid' => $existing['uid_hex'],
			'status'      => $existing['status'],
			'duplicated'  => TRUE,
			'trace_id'    => $this->trace_id,
		));
	}

	/**
	 * 본문 파싱.
	 *
	 * Content-Type 을 보지 않는다. Conversion.php 와 같은 이유다 —
	 * 헤더 하나 빠뜨렸다고 415 로 돌려보내면 결제 시도 한 건이 사라진다.
	 */
	private function body()
	{
		$raw = $this->input->raw_input_stream;

		if ( ! is_string($raw) OR $raw === '')
		{
			return array();
		}

		$parsed = json_decode($raw, TRUE);

		return is_array($parsed) ? $parsed : array();
	}

	/** 배열·객체가 오면 빈 문자열이 되어 필수·화이트리스트 검사에 걸린다. */
	private static function text($raw)
	{
		return (is_string($raw) OR is_int($raw)) ? trim((string) $raw) : '';
	}

	/** 폼에서 온 "9900" 도 받는다. 소수부가 있으면 NULL — 상품표와 어긋난다. */
	private static function integer($raw)
	{
		if (is_int($raw))
		{
			return $raw;
		}

		if (is_string($raw) && preg_match('/\A-?\d{1,18}\z/', trim($raw)) === 1)
		{
			return (int) trim($raw);
		}

		if (is_float($raw) && $raw === floor($raw) && abs($raw) < 9.0e18)
		{
			return (int) $raw;
		}

		return NULL;
	}
}
