<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Attribution\Touchpoint;
use App\Attribution\TouchpointResolver;
use App\Support\AcceptLanguage;

/**
 * 방문자 식별과 유입 기록. CI3 와 도메인 로직 사이의 얇은 다리.
 *
 * 여기가 하는 일은 셋이다.
 *   ① 쿠키에서 방문을 찾거나 새로 만들고 쿠키를 발급한다
 *   ② 요청에서 유입 접점을 뽑는다
 *   ③ 판정을 TouchpointResolver 에 맡기고 결과를 모델에 넘긴다
 *
 * 판단은 하지 않는다. 판단은 src/ 에 있다 → ADR-017
 *
 * **쿠키에는 방문 식별자만 담는다.** 유입 파라미터를 쿠키에 직렬화하면
 * 대상 조직의 CI2 세션과 같은 문제가 생긴다 — 4KB 를 넘으면 조용히 잘리고,
 * 잘렸다는 걸 아무도 모른다. 본체는 서버에 둔다. → ADR-001
 */
class Visitor
{
	/** @var CI_Controller */
	private $CI;

	/** @var array|null 이번 요청에서 확정된 방문 [id, uid_hex] */
	private $visit = NULL;

	public function __construct()
	{
		$this->CI =& get_instance();
		$this->CI->load->model('visit_model');
	}

	/**
	 * 이번 요청의 방문. 없으면 만들고 쿠키를 내려준다.
	 *
	 * @return array [id, uid_hex, is_new]
	 */
	public function current()
	{
		if ($this->visit !== NULL)
		{
			return $this->visit;
		}

		$name   = getenv('COOKIE_VID_NAME') ?: 'ab_vid';
		$cookie = $this->CI->input->cookie($name, TRUE);

		if (is_string($cookie))
		{
			$id = $this->CI->visit_model->findByUid($cookie);

			if ($id !== NULL)
			{
				// 쿠키가 유효하면 재발급하지 않는다. 만료를 연장하려고 매 요청
				// Set-Cookie 를 내리면 응답 크기만 늘고 얻는 게 없다.
				return $this->visit = array('id' => $id, 'uid_hex' => $cookie, 'is_new' => FALSE);
			}
			// 쿠키는 있는데 방문이 없다 — 파기 배치가 지운 뒤 돌아온 경우다.
			// 3개월이 지나면 정상적으로 일어나는 일이므로 새로 만든다.
		}

		// 쿠키가 없으면 브리지가 붙여 준 vid 를 본다.
		// 이것이 파라미터 전달 방식 B(VID)의 목적지 쪽 절반이다 —
		// 쿠키가 차단된 환경에서도 브리지에서 랜딩까지는 방문이 이어진다.
		//
		// 남의 vid 를 손으로 넣어 볼 수는 있다. 그래도 되는 값이라 그대로 뒀다 —
		// vid 로 할 수 있는 일은 이미 만들어진 방문 한 건에 접점을 갱신하는 것뿐이고,
		// 권한도 개인정보도 붙어 있지 않다. 세션 식별자와는 다른 물건이다.
		//
		// 대신 여기서 쿠키를 발급하므로, 링크를 공유받은 사람은 그 방문에
		// **흡수된다.** 유입 건수가 부풀지 않는 대가로 두 사람이 한 방문이 된다.
		// 의도한 트레이드오프다 → docs/failure-scenarios.md C-2
		$fromQuery = $this->CI->input->get('vid', TRUE);

		if (is_string($fromQuery))
		{
			$id = $this->CI->visit_model->findByUid($fromQuery);

			if ($id !== NULL)
			{
				// 쿠키가 없었으므로 여기서는 발급한다.
				$this->issueCookie($name, $fromQuery);

				return $this->visit = array('id' => $id, 'uid_hex' => $fromQuery, 'is_new' => FALSE);
			}
		}

		$created = $this->CI->visit_model->create($this->context());
		$this->issueCookie($name, $created['uid_hex']);

		return $this->visit = array(
			'id'      => $created['id'],
			'uid_hex' => $created['uid_hex'],
			'is_new'  => TRUE,
		);
	}

	/**
	 * 이번 요청의 유입을 기록한다.
	 *
	 * @param array|null $query 기본값은 현재 요청의 쿼리스트링
	 * @return array 판정 결과 요약 (로그·진단용)
	 */
	public function record(array $query = NULL)
	{
		$visit    = $this->current();
		$query    = $query === NULL ? $this->CI->input->get() : $query;
		$incoming = Touchpoint::fromQuery(is_array($query) ? $query : array(), new DateTimeImmutable('now', new DateTimeZone('UTC')));

		$existing   = $this->CI->visit_model->touchpoints($visit['id']);
		$resolution = (new TouchpointResolver())->resolve($existing['first'], $existing['last'], $incoming);

		$this->CI->visit_model->apply($visit['id'], $resolution);

		return array(
			'visit_id'  => $visit['id'],
			'is_direct' => $incoming->isDirect(),
			'first'     => $resolution->createFirst !== NULL ? 'created' : 'kept',
			'last'      => $resolution->upsertLast !== NULL ? 'updated' : 'kept',
		);
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 쿠키 속성은 docs/domains-and-cookies.md 3장의 정책표와 일치해야 한다.
	 *
	 * SameSite=Lax 인 이유: 이 쿠키는 광고주 도메인 안에서만 쓴다.
	 * 브리지의 302 도 같은 사이트 안이라 Lax 로 충분하다.
	 * 크로스사이트로 나가는 쿠키는 수집 도메인 쪽의 별도 쿠키다.
	 *
	 * CI3 의 set_cookie() 를 쓰지 않는다. 3.1.13 은 samesite 를 지원하지만
	 * 속성을 한눈에 보이게 두는 편이 정책표와 대조하기 쉽다.
	 */
	private function issueCookie($name, $uidHex)
	{
		$shop = getenv('SHOP_DOMAIN') ?: '';

		setcookie($name, $uidHex, array(
			'expires'  => time() + (int) (getenv('COOKIE_VID_MAX_AGE') ?: 31536000),
			'path'     => '/',
			'domain'   => $shop !== '' ? '.'.$shop : '',
			'secure'   => TRUE,
			'httponly' => TRUE,
			'samesite' => 'Lax',
		));
	}

	/** 방문 생성에 필요한 요청 맥락. */
	private function context()
	{
		return array(
			'landing_path' => (string) $this->CI->input->server('REQUEST_URI'),
			'referrer'     => (string) $this->CI->input->server('HTTP_REFERER'),
			'ua'           => $this->CI->input->user_agent(),
			'ip'           => $this->CI->input->ip_address(),
			'lang'         => AcceptLanguage::primary($this->CI->input->server('HTTP_ACCEPT_LANGUAGE')),
			// 국가는 아직 채우지 않는다. 지오IP 소스가 없고,
			// 없는 값을 추측해 넣으면 집계가 조용히 틀어진다.
			'country'      => NULL,
		);
	}
}
