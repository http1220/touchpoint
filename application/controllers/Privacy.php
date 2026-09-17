<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Attribution\AdOptOut;

/**
 * 개인정보처리방침 · 맞춤형 광고 거부.
 *
 *   GET /privacy            방침 (루트·lp.·app. 어디서나)
 *   GET /privacy/ads?off=1  맞춤형 광고 거부 쿠키를 건다
 *   GET /privacy/ads?off=0  거부를 푼다
 *
 * ── 왜 이 페이지가 픽셀보다 먼저인가 ──
 *
 * 픽셀을 켜면 브라우저에 `_fbp` 가 생기고 방문 행태가 Meta 로 간다.
 * 서버 전환(CAPI)도 UA·IP 원문을 Meta 로 보낸다. 국내 기준으로 이건
 * **고지하고 거부할 수단을 주는 것**이 전제다 — 코드보다 문장이 먼저다.
 * → docs/decisions/ADR-005-channel-adapter.md 「결정」
 *
 * ── 방침의 내용은 코드에서 왔다 ──
 *
 * 항목·보존기간·쿠키 이름을 **지금 코드가 실제로 하는 것**에서 옮겼다.
 * 코드가 바뀌면 이 뷰도 바뀌어야 한다. 방침이 코드보다 많이 적으면 거짓이고,
 * 적게 적으면 고지 누락이다.
 *
 * ── 거부가 무엇을 끄는가 ──
 *
 *   Meta 픽셀           출력하지 않는다          views/partials/meta_pixel.php
 *   Meta 서버 전송      브라우저 맥락을 싣지 않는다 → 어댑터가 보내지 않는다
 *                                               MY_Controller::browserContext()
 *   GA4                 끄지 않는다 — 분석이지 맞춤형 광고가 아니다. 대신
 *                       Google 의 차단 도구를 방침에 안내한다
 *
 * GET 으로 바꾼다. 상태를 바꾸는 GET 은 원칙에 어긋나지만, 여기서 바뀌는 것은
 * **추적을 줄이는 방향의 본인 쿠키 하나**이고, 누가 링크로 남에게 누르게 해도
 * 피해가 "광고 추적이 꺼진다" 뿐이다. CSRF 토큰이 필요한 폼보다 링크 하나가
 * 거부 수단으로 더 쉽다 — 거부는 쉬워야 한다.
 */
class Privacy extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->requireHost(array('root', 'lp', 'app'));
	}

	public function index()
	{
		$this->output->set_header('Cache-Control: no-store');

		$this->load->view('privacy/index', array(
			'shop'      => (string) (getenv('SHOP_DOMAIN') ?: ''),
			'contact'   => trim((string) (getenv('PRIVACY_CONTACT') ?: '')),
			'opted_out' => AdOptOut::isOn($this->input->cookie(AdOptOut::COOKIE, TRUE)),
			'pixel_on'  => tp_env_bool('META_PIXEL_BROWSER'),
			'done'      => $this->input->get('done', TRUE),
			// 바닥글이 쓴다. 이 화면은 DB 를 읽지 않으므로 읽기 대상은 넘기지 않는다
			'trace_id'  => $this->trace_id,
		));
	}

	public function ads()
	{
		$off  = $this->input->get('off', TRUE) === '1';
		$shop = (string) (getenv('SHOP_DOMAIN') ?: '');

		setcookie(AdOptOut::COOKIE, $off ? '1' : '', array(
			'expires'  => $off ? time() + AdOptOut::MAX_AGE : time() - 3600,
			'path'     => '/',

			// 등록 도메인에 건다. 거부는 lp.·app.·api. 전부에 효력이 있어야 한다
			'domain'   => $shop !== '' ? '.'.$shop : '',
			'secure'   => TRUE,
			'httponly' => TRUE,
			'samesite' => 'Lax',
		));

		/*
		 * 이미 브라우저에 있는 `_fbp`·`_fbc` 도 지운다. 픽셀이 등록 도메인에 건다.
		 * 남겨 두면 거부한 뒤에도 서버가 읽을 수 있다(읽지 않게 막아 두었지만
		 * 쓰지 않을 값을 남겨 둘 이유가 없다).
		 */
		if ($off)
		{
			foreach (array('_fbp', '_fbc') as $name)
			{
				setcookie($name, '', array('expires' => time() - 3600, 'path' => '/', 'domain' => $shop !== '' ? '.'.$shop : ''));
			}
		}

		$this->output
			->set_status_header(303)
			->set_header('Cache-Control: no-store')
			->set_header('Location: /privacy?done='.($off ? 'off' : 'on').'#ads');
	}
}
