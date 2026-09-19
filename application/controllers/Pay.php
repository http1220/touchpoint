<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\CurlHttpClient;
use App\Payment\CoinProduct;
use App\Payment\Gateway\Checkout;
use App\Payment\Gateway\GatewayResult;
use App\Payment\Gateway\InicisEndpoints;
use App\Payment\PayAccess;
use App\Payment\PaymentStatus;
use App\Payment\StubWebhook;
use App\Payment\WebhookSignature;

/**
 * 실PG 결제(이니시스). `app.<도메인>` 에서만 열린다 → docs/plan-multi-pg.md
 *
 *   GET  /pay                 시연 결제 화면. 입장권이 없으면 입장권 받기 안내
 *   POST /pay/access          입장권 발급 → 303 /pay  (안내 화면 /tour 의 버튼이 부른다)
 *   POST /pay/stub/confirm    카드 없는 한 바퀴 — 가짜 PG 가 확정 웹훅을 두 번 보낸다
 *   POST /pay/inicis/start    결제창 필드에 서명해 돌려준다
 *   POST /pay/inicis/return   이니시스 복귀 → 승인 → 장부 (없으면 망취소)
 *   *    /pay/inicis/close    결제창 닫기
 *   GET  /pay/result/{uid}    결과
 *
 * ── 문은 둘이다 ──
 *
 *   입장권 쿠키(PayAccess)      /pay · start · result — **누구나 버튼 한 번으로 받는다**(09-19 오후,
 *                                C4 를 뒤집었다). 막는 것은 사람이 아니라 크롤러와 "모르고 누르기" 다
 *   이니시스 흐름                return — **쿠키가 실리지 않는다.** 교차 사이트 POST 라
 *                                SameSite=Lax 쿠키(세션·ab_vid·tp_pay)가 없다 → C3.
 *                                결제는 쿠키가 아니라 orderNumber 로 찾는다
 *
 * ── return 이 지키는 순서 ──
 *
 *   ① 결제를 찾는다. 실패하면 승인을 요청하지 않는다 — **승인 전 거절은 돈이 안 움직인다**
 *   ② confirm: authUrl 검사 → 승인 → 금액 대조 (InicisGateway)
 *   ③ captured → applyEvent. **이 요청의 승인이 장부에 오르지 않았으면 이 요청이 되돌린다**(망취소)
 *   ④ 303 → /pay/result/{uid}  (새로고침이 복귀 POST 를 다시 보내지 않게)
 *
 * 브라우저 입력만 보고 거절한 것(rejected)은 장부를 바꾸지 않는다 → GatewayResult 주석.
 */
class Pay extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->requireHost('app');
		$this->load->model('payment_model');
		$this->load->library('gateways');
	}

	/** GET /pay */
	public function index()
	{
		$secret = $this->secret();

		if (PayAccess::tokenMatches($this->input->get('t', FALSE), $secret))
		{
			// 운영자 지름길. 주소에서 비밀을 지운다 — 남기면 방문 기록·Referer 로 샌다.
			$this->grant($secret);
			$this->output->set_status_header(303)->set_header('Location: /pay');

			return;
		}

		if ( ! $this->hasAccess())
		{
			$this->showGate();

			return;
		}

		$mode = $this->gateways->inicisMode();

		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->load->view('pay/index', array(
			'products'   => $this->krwProducts(),
			'user_uid'   => strtolower(trim((string) (getenv('PAY_DEMO_USER_UID') ?: ''))),
			'inicis_js'  => $mode === '' ? NULL : InicisEndpoints::js($mode),
			'inicis_on'  => $this->gateways->get('inicis') !== NULL,
			'mode'       => $mode,

			// 엣지가 판별한다(nginx.conf map $is_mobile → php-pass.conf). 모바일이면 이니시스 PC
			// 결제창 대신 안내 — INIStdPay 가 모바일에서 "Dev. Error" 경고만 띄운다(09-19 실측) → B10
			'is_mobile'  => $this->server('AB_IS_MOBILE') === '1',
		));
	}

	/**
	 * POST /pay/access — 입장권을 받는다. 안내 화면(루트 도메인)의 폼이 부른다.
	 *
	 * **POST 인 이유**: 링크를 따라가는 크롤러·메신저 미리보기가 입장권을 받지 않게.
	 * CSRF 제외 목록에 있다 — 남의 브라우저에 입장권을 쥐여 주는 것은 피해가 없다.
	 * 결제는 여전히 그 사람이 버튼을 누르고 카드를 넣어야 일어난다.
	 */
	public function access()
	{
		$this->requirePost();

		$secret = $this->secret();

		if ($secret === '')
		{
			// 설정이 없으면 아무도 못 연다. 안내는 그대로 보여 준다.
			$this->showGate();

			return;
		}

		$this->grant($secret);
		$this->output->set_status_header(303)->set_header('Location: /pay');
	}

	/**
	 * POST /pay/stub/confirm  {payment_uid} — **카드 없는 한 바퀴.** 가짜 PG 가 확정 웹훅을 두 번 보낸다.
	 *
	 * 면접관 대부분은 모르는 사이트에 실카드를 넣지 않는다. 그러면 이니시스 경로로는
	 * "결제창이 뜬다" 까지만 보이고, 결과 화면(장부 · 코인 · 매체 전송)에는 닿지 않는다
	 * → docs/plan-multi-pg.md 6장 「카드 없는 길」
	 *
	 * ── 진짜 문을 탄다 ──
	 *
	 * applyEvent 를 직접 부르지 않고, 서명한 웹훅을 **자기 공개 주소의 /webhooks/pg 로
	 * 실제 HTTP 로** 보낸다. HMAC 검증 · 웹훅 컨트롤러 · 응답 코드까지 운영 경로 그대로다.
	 * 웹 요청 안에서 외부 HTTP 를 부르는 것은 이니시스 승인(B11)과 같은 예외이고,
	 * 자기에게 보내는 요청이 php-fpm 작업자 하나를 더 잡는다(pm.max_children = 8).
	 *
	 * ── 같은 바이트를 두 번 ──
	 *
	 * 두 번째는 무시(200 ignored)되고 장부에 `from = to` 줄로 남는다. "같은 알림이 두 번
	 * 와도 한 번만 센다" 가 면접 전에, 결과 화면에서 10초 만에 보인다. 코인은 한 번만 나간다.
	 *
	 * ── 아무 결제나 확정하지 못한다 ──
	 *
	 * 입장권은 누구나 받는다. 그래서 **이 버튼이 방금 만든 결제만**: 스텁 · created ·
	 * `demo-` 키 · 10분 이내 → src/Payment/StubWebhook::demoRejects. D-3 측정용 결제나
	 * 남의 결제를 골라 captured 로 만들 수 없다.
	 *
	 * 매체로는 **그대로 보낸다**(사용자 결정 — 스텁 결제의 D2 와 같다). 운영 GA4 에 표시 없이 들어간다.
	 */
	public function stub_confirm()
	{
		$this->requirePost();
		$this->requireAccess();

		$body    = json_decode((string) $this->input->raw_input_stream, TRUE);
		$uid     = strtolower(trim((string) (is_array($body) ? ($body['payment_uid'] ?? '') : '')));
		$payment = preg_match('/\A[0-9a-f]{32}\z/', $uid) === 1 ? $this->payment_model->findByUid($uid) : NULL;

		if ($payment === NULL)
		{
			$this->problem(404, 'payment-not-found', '그런 결제가 없습니다.');
		}

		$createdAt = (new DateTimeImmutable($payment['created_at'], new DateTimeZone('UTC')))->getTimestamp();
		$why       = StubWebhook::demoRejects($payment['pg'], $payment['status'], $payment['idempotency_key'], $createdAt, time());

		if ($why !== NULL)
		{
			$this->problem(409, 'not-a-demo-payment', '이 버튼이 방금 만든 시연 결제만 확정합니다.', array('reason' => $why));
		}

		$secret = (string) (getenv('PG_WEBHOOK_SECRET') ?: '');

		if ($secret === '')
		{
			$this->problem(503, 'pg-unavailable', '가짜 PG 의 서명 키가 없습니다.');
		}

		$raw = StubWebhook::body(
			$uid,
			PaymentStatus::CAPTURED,
			$payment['amount_minor'],
			$payment['currency'],
			'evt_'.bin2hex(tp_uuid7()),
			gmdate('Y-m-d\TH:i:s').'.000Z'
		);

		$signer = new WebhookSignature($secret, (int) (getenv('PG_WEBHOOK_TOLERANCE_SEC') ?: WebhookSignature::DEFAULT_TOLERANCE_SEC));
		$sig    = $signer->header($raw, time());
		$http   = new CurlHttpClient('touchpoint-stub-pg/1.0');
		$url    = tp_host_url('app', '/webhooks/pg');

		$deliveries = array();

		foreach (array(1, 2) as $n)
		{
			// 같은 본문 · 같은 서명 헤더. 바이트까지 같은 재전송이다 → cli/pg 의 sign 과 같은 성질
			$res  = $http->postJson($url, $raw, array(WebhookSignature::HEADER => $sig), 10000);
			$json = $res->json();

			$deliveries[] = array(
				'http'   => $res->status,
				'result' => isset($json['result']) ? (string) $json['result'] : NULL,
				'error'  => $res->error,
			);
		}

		log_message('info', sprintf('pay stub demo: payment=%s 1st=%s/%s 2nd=%s/%s', $uid,
			(string) $deliveries[0]['http'], (string) $deliveries[0]['result'],
			(string) $deliveries[1]['http'], (string) $deliveries[1]['result']));

		$this->json(200, array(
			'result_url' => '/pay/result/'.$uid,
			'deliveries' => $deliveries,
			'trace_id'   => $this->trace_id,
		));
	}

	/** POST /pay/inicis/start  {payment_uid} */
	public function inicis_start()
	{
		$this->requirePost();
		$this->requireAccess();

		$body    = json_decode((string) $this->input->raw_input_stream, TRUE);
		$payment = $this->payment_model->findByUid(strtolower(trim((string) ($body['payment_uid'] ?? ''))));

		if ($payment === NULL OR $payment['pg'] !== 'inicis')
		{
			$this->problem(404, 'payment-not-found', '이니시스로 시작한 결제가 없습니다.');
		}

		if ($payment['status'] !== PaymentStatus::CREATED)
		{
			// 이미 진행된 결제로 결제창을 또 열면 승인이 두 번 날 수 있다.
			$this->problem(409, 'payment-not-open', '이미 진행된 결제입니다.', array('status' => $payment['status']));
		}

		$gateway = $this->gatewayOr503();
		$product = CoinProduct::findByPrice($payment['amount_minor'], $payment['currency']);

		$this->json(200, $gateway->checkout(new Checkout(
			$payment['uid_hex'],
			$payment['amount_minor'],
			$payment['currency'],
			$product === NULL ? '코인' : '코인 '.$product->coins.'개',

			// B9: 이니시스 필수값. 시연용 고정값이고 실제 개인정보가 아니다 → .env
			(string) (getenv('PAY_DEMO_BUYER_NAME') ?: '시연 구매자'),
			(string) (getenv('PAY_DEMO_BUYER_TEL') ?: '010-0000-0000'),
			(string) (getenv('PAY_DEMO_BUYER_EMAIL') ?: ''),

			tp_host_url('app', '/pay/inicis/return'),
			tp_host_url('app', '/pay/inicis/close')
		)));
	}

	/** POST /pay/inicis/return — 이니시스가 브라우저를 통해 보낸다. 쿠키 없음 */
	public function inicis_return()
	{
		$this->requirePost();

		$post = $this->input->post(NULL, FALSE);
		$post = is_array($post) ? $post : array();
		$uid  = strtolower(trim((string) ($post['orderNumber'] ?? '')));

		$payment = preg_match('/\A[0-9a-f]{32}\z/', $uid) === 1 ? $this->payment_model->findByUid($uid) : NULL;

		// ① 승인 전 거절은 돈이 안 움직인다. 로그만 남기고 끝낸다.
		if ($payment === NULL OR $payment['pg'] !== 'inicis')
		{
			log_message('error', 'pay return: 결제를 찾지 못해 승인하지 않는다. orderNumber='.substr($uid, 0, 40));
			$this->showOutcome(NULL, '결제를 찾지 못했습니다. 청구되지 않았습니다.');

			return;
		}

		if ( ! in_array($payment['status'], array(PaymentStatus::CREATED, PaymentStatus::PENDING), TRUE))
		{
			// 새로고침·두 번 누름. 이미 끝난 결제에 승인을 또 요청하지 않는다.
			$this->seeResult($uid);

			return;
		}

		$gateway = $this->gateways->get('inicis');

		if ($gateway === NULL)
		{
			log_message('error', 'pay return: 이니시스 어댑터가 없어 승인하지 않는다. payment='.$uid);
			$this->showOutcome(NULL, '지금은 결제할 수 없습니다. 청구되지 않았습니다.');

			return;
		}

		// ② 승인
		$r = $gateway->confirm($post, $uid, $payment['amount_minor'], $payment['currency']);

		// ③ 장부
		if ($r->isCaptured())
		{
			$a = $this->payment_model->applyEvent($payment, PaymentStatus::CAPTURED, $r->stored, 'return', $r->refs);

			if ( ! $a['applied'] OR $a['error'] !== NULL)
			{
				/*
				 * **이 요청의 승인이 장부에 오르지 않았다.** 이 요청이 되돌린다.
				 *
				 * 이미 다른 요청이 captured 로 만든 경우(동시 복귀)도 여기 온다 —
				 * 그 승인과 이 승인은 다른 tid 다. 이걸 남겨 두면 한 결제에 두 번
				 * 청구된다.
				 */
				$this->undo($gateway, $r, $payment, 'booking-failed: '.($a['error'] ?? 'ignored, status='.$a['status']));
			}
		}
		elseif ($r->mustCompensate())
		{
			// 응답 없음 · 금액 불일치 — PG 에 승인이 남아 있을 수 있다
			$this->undo($gateway, $r, $payment, (string) $r->reason);
		}
		elseif ($r->outcome === GatewayResult::FAILED)
		{
			// PG 가 거절했다. 되돌릴 승인이 없다.
			$this->payment_model->applyEvent($payment, PaymentStatus::FAILED, $r->stored + array('reason' => $r->reason), 'return');
		}
		else
		{
			// rejected: 브라우저 입력만 보고 거절했다. 장부를 바꾸지 않는다 → GatewayResult 주석
			log_message('error', 'pay return: 승인 전에 거절했다(장부 불변). payment='.$uid.' reason='.$r->reason);
		}

		// ④
		$this->seeResult($uid);
	}

	/**
	 * 결제창 닫기. 이니시스가 결제창 안에서 부른다.
	 *
	 * [추정] 닫기 스크립트 주소(`/stdjs/INIStdPay_close.js`)는 공식 샘플에 있는데
	 * 샘플이 가맹점 로그인 뒤라 보지 못했다. 그리고 이 페이지는 **이니시스 결제창
	 * (다른 오리진) 안의 프레임**에서 열릴 수 있다 — 엣지가 모든 응답에
	 * `X-Frame-Options: SAMEORIGIN` 을 붙이므로 그때는 그려지지 않는다.
	 * 무과금 확인 ①(결제창 열고 닫기)에서 판정한다 → plan-multi-pg.md 6장
	 */
	public function inicis_close()
	{
		$mode = $this->gateways->inicisMode();
		$host = $mode === InicisEndpoints::MODE_LIVE ? 'https://stdpay.inicis.com' : 'https://stgstdpay.inicis.com';

		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->load->view('pay/close', array('close_js' => $host.'/stdjs/INIStdPay_close.js'));
	}

	/** GET /pay/result/{uid} */
	public function result($uid = NULL)
	{
		if ( ! $this->hasAccess())
		{
			$this->showGate();

			return;
		}

		$uid     = strtolower(trim((string) $uid));
		$payment = preg_match('/\A[0-9a-f]{32}\z/', $uid) === 1 ? $this->payment_model->findByUid($uid) : NULL;

		if ($payment === NULL)
		{
			$this->problem(404, 'payment-not-found', '그런 결제가 없습니다.');
		}

		$this->showOutcome($payment, NULL);
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 장부에 올리지 않을 승인을 되돌린다(망취소).
	 *
	 * 되돌렸으면 failed 로 적는다 — 결제가 created 로 남아 오래된 결제 보고에
	 * 잡히는 것보다, 무슨 일이 있었는지가 이벤트에 남는 편이 낫다. 이미 다른
	 * 요청이 captured 로 만들었으면 CAS 가 이 failed 를 무시한다.
	 *
	 * **되돌리지 못했으면** 사람이 봐야 한다. 테스트 MID 는 자정 전 자동 취소가
	 * 안전망이지만, 운영이면 청구가 남는다.
	 */
	private function undo($gateway, GatewayResult $r, array $payment, $why)
	{
		$ok = $gateway->compensate($r, $payment['amount_minor']);

		log_message('error', sprintf(
			'pay return: 승인을 장부에 올리지 않고 망취소 %s. payment=%s why=%s tid=%s',
			$ok ? '성공' : '**실패 — 사람이 봐야 한다**',
			$payment['uid_hex'], $why, $r->refs['tid'] ?? '-'
		));

		$this->payment_model->applyEvent($payment, PaymentStatus::FAILED, $r->stored + array(
			'reason'           => $why,
			'net_cancelled'    => $ok,
			'unbooked_tid'     => $r->refs['tid'] ?? NULL,
		), 'return');
	}

	private function seeResult($uid)
	{
		$this->output->set_status_header(303)->set_header('Location: /pay/result/'.$uid);
	}

	private function showOutcome($payment, $message)
	{
		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->load->view('pay/result', array(
			'payment' => $payment,
			'events'  => $payment === NULL ? array() : $this->payment_model->events($payment['id']),
			'refs'    => $payment === NULL ? array() : $this->payment_model->findPgRefs($payment['id']),
			'effects' => $payment === NULL ? NULL : $this->payment_model->effects($payment),
			'message' => $message,
		));
	}

	/** @return list<CoinProduct> */
	private function krwProducts()
	{
		$out = array();

		foreach (CoinProduct::codes() as $code)
		{
			$p = CoinProduct::find($code);

			if ($p !== NULL && $p->currency === 'KRW')
			{
				$out[] = $p;
			}
		}

		return $out;
	}

	private function gatewayOr503()
	{
		$gateway = $this->gateways->get('inicis');

		if ($gateway === NULL)
		{
			$this->output->set_header('Retry-After: 600');
			$this->problem(503, 'pg-unavailable', '이니시스 설정이 없어 결제할 수 없습니다.');
		}

		return $gateway;
	}

	private function secret()
	{
		return (string) (getenv('PAY_DEMO_TOKEN') ?: '');
	}

	private function hasAccess()
	{
		return PayAccess::allows($this->input->cookie(PayAccess::COOKIE, FALSE), $this->secret(), time());
	}

	/**
	 * API(POST) 용. 입장권이 없으면 403.
	 *
	 * 처음엔 404 였다 — 잠긴 문이 있다는 것도 알리지 않으려고. 안내 화면에 버튼이
	 * 생긴 뒤로는 문이 공개돼 있으니 숨길 이유가 없고, 이유를 말하는 편이 낫다.
	 */
	private function requireAccess()
	{
		if ( ! $this->hasAccess())
		{
			$this->problem(403, 'pay-locked', '입장권이 없습니다. 안내 화면이나 /pay 에서 먼저 받으세요.');
		}
	}

	/** 입장권이 없을 때의 화면. 받는 버튼이 여기도 있다 — 안내를 거치지 않고 /pay 로 곧장 온 사람 */
	private function showGate()
	{
		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->load->view('pay/gate', array('open' => $this->secret() !== ''));
	}

	private function grant($secret)
	{
		setcookie(PayAccess::COOKIE, PayAccess::issue($secret, time()), array(
			'expires'  => time() + PayAccess::TTL,
			'path'     => '/',       // /purchase 도 이 쿠키를 본다 (pg≠stub)
			'domain'   => '',        // app. 호스트에만
			'secure'   => TRUE,
			'httponly' => TRUE,
			'samesite' => 'Lax',
		));
	}

	private function requirePost()
	{
		if (strtoupper($this->server('REQUEST_METHOD')) !== 'POST')
		{
			$this->problem(405, 'method-not-allowed', 'POST 만 받습니다.');
		}
	}
}
