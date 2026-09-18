<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Payment\CoinProduct;
use App\Payment\Gateway\Checkout;
use App\Payment\Gateway\GatewayResult;
use App\Payment\Gateway\InicisEndpoints;
use App\Payment\PayAccess;
use App\Payment\PaymentStatus;

/**
 * 실PG 결제(이니시스). `app.<도메인>` 에서만 열린다 → docs/plan-multi-pg.md
 *
 *   GET  /pay                 시연 결제 화면 (?t=<토큰> 으로 들어와 쿠키를 받는다)
 *   POST /pay/inicis/start    결제창 필드에 서명해 돌려준다
 *   POST /pay/inicis/return   이니시스 복귀 → 승인 → 장부 (없으면 망취소)
 *   *    /pay/inicis/close    결제창 닫기
 *   GET  /pay/result/{uid}    결과
 *
 * ── 문은 둘이다 ──
 *
 *   시연 토큰 쿠키(PayAccess)   /pay · start · result
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
		$token = (string) (getenv('PAY_DEMO_TOKEN') ?: '');

		if (PayAccess::tokenMatches($this->input->get('t', FALSE), $token))
		{
			$this->grant($token);

			// 주소에서 토큰을 지운다. 남기면 방문 기록·Referer 로 샌다.
			$this->output->set_status_header(303)->set_header('Location: /pay');

			return;
		}

		$this->requireAccess();

		$mode = $this->gateways->inicisMode();

		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->load->view('pay/index', array(
			'products'   => $this->krwProducts(),
			'user_uid'   => strtolower(trim((string) (getenv('PAY_DEMO_USER_UID') ?: ''))),
			'inicis_js'  => $mode === '' ? NULL : InicisEndpoints::js($mode),
			'inicis_on'  => $this->gateways->get('inicis') !== NULL,
			'mode'       => $mode,
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
		$this->requireAccess();

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

	/**
	 * 토큰 쿠키. 없으면 **404** — 잠긴 문이 있다는 사실도 알려 주지 않는다.
	 * requireHost 가 호스트가 틀렸을 때 주는 응답과 같다.
	 */
	private function requireAccess()
	{
		$token = (string) (getenv('PAY_DEMO_TOKEN') ?: '');

		if ( ! PayAccess::allows($this->input->cookie(PayAccess::COOKIE, FALSE), $token))
		{
			$this->problem(404, 'not-found', '이 호스트에는 없는 경로입니다.');
		}
	}

	private function grant($token)
	{
		setcookie(PayAccess::COOKIE, PayAccess::cookieValue($token), array(
			'expires'  => time() + 86400,
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
