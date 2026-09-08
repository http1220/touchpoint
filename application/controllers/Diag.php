<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 인프라 진단.
 *
 * CI3 를 올리기 전 public/index.php 에 임시로 있던 진단 페이지를
 * 컨트롤러로 옮긴 것이다. 확인 대상은 그대로다 —
 * DNS · TLS · 호스트 라우팅 · HTTP 버전 · 쿠키 속성.
 * 여기에 CI3 이후 생긴 두 가지를 더한다 — 읽기 복제본 배정, 상관 ID.
 *
 * 비밀값은 내려주지 않는다. 도메인 이름과 런타임 버전만 나온다.
 * 그래서 운영에서도 열어 둔다 — 면접에서 이 화면 하나로 구성을 설명할 수 있다.
 */
class Diag extends MY_Controller
{
	public function index()
	{
		$shop  = getenv('SHOP_DOMAIN') ?: '';
		$track = getenv('TRACK_DOMAIN') ?: '';
		$host  = strtolower($this->server('HTTP_HOST'));

		$role = $this->role($host, $shop, $track);

		$this->issueProbeCookies($role[0], $shop, $track);

		$data = array(
			'role'  => $role,
			'host'  => $host,
			'shop'  => $shop,
			'track' => $track,
			'rows'  => array(
				'호스트'         => $host,
				'역할'           => $role[0].' — '.$role[1],
				'쿠키 관점'      => $role[2],
				'스킴'           => $this->scheme(),
				'HTTP 버전'      => $this->server('SERVER_PROTOCOL'),
				'읽기 대상'      => $this->readTarget().'  (Lua 배정)',
				'상관 ID'        => $this->trace_id,
				'SHOP_DOMAIN'    => $shop !== '' ? $shop : '(미설정)',
				'TRACK_DOMAIN'   => $track !== '' ? $track : '(미설정)',
				'PHP'            => PHP_VERSION,
				'CodeIgniter'    => CI_VERSION,
				'환경'           => ENVIRONMENT,
				'억제된 deprecation' => MY_Exceptions::suppressed().'건 (프레임워크 내부)',
				'서버 시각(UTC)' => tp_now_utc(),
			),
		);

		$this->output->set_header('Cache-Control: no-store');
		$this->load->view('diag/index', $data);
	}

	/** 호스트로 역할을 판별한다. 라우팅이 의도대로 갈렸는지 눈으로 보는 것이 목적이다. */
	private function role($host, $shop, $track)
	{
		if ($shop !== '' && $host === 'lp.'.$shop)   return array('lp',  '광고주 측 · 랜딩/브리지', 'first-party');
		if ($shop !== '' && $host === 'm.'.$shop)    return array('m',   '광고주 측 · 모바일',      'first-party');
		if ($shop !== '' && $host === 'app.'.$shop)  return array('app', '광고주 측 · 서비스/전환', 'first-party');
		if ($track !== '' && $host === 'api.'.$track) return array('api', '추적 측 · 수집 API',      'third-party');

		return array('?', '알 수 없는 호스트', '-');
	}

	private function scheme()
	{
		return ($this->server('HTTPS') !== '' OR $this->server('REQUEST_SCHEME') === 'https') ? 'https' : 'http';
	}

	/**
	 * 정책표와 같은 속성으로 시험 쿠키를 발급한다.
	 *
	 * 문서에 적은 속성과 브라우저가 실제로 받아들이는 속성이 다른 경우가 있다.
	 * DevTools 에서 눈으로 확인할 대상을 만들어 두는 것이 이 코드의 전부다.
	 * → docs/domains-and-cookies.md 3장
	 */
	private function issueProbeCookies($role, $shop, $track)
	{
		if ($this->scheme() !== 'https')
		{
			return;
		}

		if ($role === 'lp' OR $role === 'app' OR $role === 'm')
		{
			setcookie('tp_probe_vid', bin2hex(random_bytes(8)), array(
				'domain'   => '.'.$shop,
				'path'     => '/',
				'samesite' => 'Lax',
				'secure'   => TRUE,
				'httponly' => TRUE,
				'expires'  => time() + 3600,
			));

			return;
		}

		if ($role === 'api')
		{
			// Partitioned(CHIPS)는 PHP 의 setcookie 가 아직 모른다. 헤더로 직접 붙인다.
			// SameSite=None 과 Secure 가 함께 없으면 Partitioned 는 효력이 없다.
			header(sprintf(
				'Set-Cookie: tp_probe_tid=%s; Domain=.%s; Path=/; Max-Age=3600; SameSite=None; Secure; HttpOnly; Partitioned',
				bin2hex(random_bytes(8)),
				$track
			), FALSE);
		}
	}
}
