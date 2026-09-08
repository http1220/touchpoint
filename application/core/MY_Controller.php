<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Support\TraceId;

/**
 * 모든 컨트롤러의 기반 층.
 *
 * 여기 있는 것은 넷뿐이다.
 *   ① 읽기 커넥션 선택 — Lua 가 배정한 복제본을 따른다
 *   ② 상관 ID       — 엣지에서 받은 값을 앱 로그까지 끌고 간다
 *   ③ 호스트 검증   — 이 경로가 이 호스트에서 열려도 되는가
 *   ④ 에러 응답     — RFC 9457 Problem Details
 *
 * 도메인 로직은 여기 두지 않는다. src/ 의 PSR-4 쪽이다. → ADR-017
 */
/*
 * PHP 8.2 의 동적 프로퍼티 deprecation 에 대한 대응.
 *
 * CI3 의 Loader 는 $this->load->library('x') 를 부를 때마다
 * $this->x 를 런타임에 만들어 붙인다. 프레임워크의 로딩 방식 자체가
 * 동적 프로퍼티라, 8.2 에서는 라이브러리·모델을 부를 때마다 경고가 난다.
 *
 * 클래스마다 public $db, public $session ... 을 일일이 선언하는 방법도 있지만,
 * 무엇을 로드하는지는 요청 경로마다 다르고 선언을 빠뜨리면 다시 경고가 난다.
 * 그래서 여기 한 곳에서 허용한다. 상속되므로 모든 컨트롤러에 적용된다.
 *
 * 이건 CI3 를 지원 범위 밖의 PHP 에서 돌리는 대가다.
 * 감추는 것이 아니라 어디서 왜 발생하는지 적어 두고 넘어간다. → ADR-001
 */
#[AllowDynamicProperties]
class MY_Controller extends CI_Controller
{
	/**
	 * 허용된 읽기 대상. 화이트리스트다.
	 *
	 * AB_READ_TARGET 은 Lua 가 넣지만 결국 요청에서 온 값이고,
	 * 그대로 문자열 결합하면 임의의 DB 그룹 이름을 만들 수 있게 된다.
	 * 값의 출처가 신뢰할 만해 보여도 목록으로 막는다.
	 */
	const READ_TARGETS = array('rdb1', 'rdb2');

	/** @var CI_DB_query_builder|null 지연 생성 */
	private $read_db = NULL;

	/** @var string 요청 상관 ID */
	protected $trace_id;

	public function __construct()
	{
		parent::__construct();

		// 엣지가 넘긴 $request_id. 없으면(CLI·로컬) 직접 만든다.
		// → docker/openresty/conf.d/php-pass.conf 의 AB_TRACE_ID
		$this->trace_id = TraceId::current();

		// 응답에 되돌려준다. 사용자가 캡처한 화면 하나로 로그를 찾을 수 있게.
		if ( ! is_cli())
		{
			header('X-Trace-Id: '.$this->trace_id);
		}
	}

	/**
	 * 읽기 커넥션.
	 *
	 * $this->db 는 언제나 프라이머리(write 그룹)다.
	 * 지연을 감수해도 되는 조회만 이쪽을 쓴다.
	 *
	 * 판단 기준은 하나 — "직전에 내가 쓴 것을 읽는가".
	 * 그렇다면 $this->db. 아니라면 여기.
	 */
	protected function read()
	{
		if ($this->read_db === NULL)
		{
			$target = $this->server('AB_READ_TARGET');

			if ( ! in_array($target, self::READ_TARGETS, TRUE))
			{
				// 배정이 없거나 모르는 값이면 지연 0인 rdb1 로 떨어진다.
				// 이쪽이 안전한 실패다 — 낯선 값을 만났을 때
				// 더 최신인 데이터를 주는 쪽으로 넘어진다.
				$target = 'rdb1';
			}

			$this->read_db = $this->load->database('read_'.$target, TRUE);
		}

		return $this->read_db;
	}

	/** 지금 요청이 배정받은 읽기 대상. 로그·진단용. */
	protected function readTarget()
	{
		$target = $this->server('AB_READ_TARGET');

		return in_array($target, self::READ_TARGETS, TRUE) ? $target : 'rdb1';
	}

	/**
	 * 이 경로가 이 호스트에서 열려도 되는가.
	 *
	 * 수집 엔드포인트가 광고주 도메인에서도 열리면 first-party 가 되어
	 * 크로스사이트 실험 자체가 성립하지 않는다. 실험 조건을 코드로 고정한다.
	 *
	 * @param string $role 'lp' | 'm' | 'app' | 'api'
	 */
	protected function requireHost($role)
	{
		$shop  = getenv('SHOP_DOMAIN') ?: '';
		$track = getenv('TRACK_DOMAIN') ?: '';
		$host  = strtolower($this->server('HTTP_HOST'));

		$expected = ($role === 'api') ? 'api.'.$track : $role.'.'.$shop;

		// 도메인이 설정되지 않은 로컬 개발에서는 검사하지 않는다.
		if ($shop === '' OR ($role === 'api' && $track === ''))
		{
			return;
		}

		if ($host !== $expected)
		{
			$this->problem(404, 'not-found', '이 호스트에는 없는 경로입니다.');
		}
	}

	/**
	 * RFC 9457 Problem Details 로 응답하고 끝낸다.
	 *
	 * 에러 본문 형식을 엔드포인트마다 다르게 만들면
	 * 클라이언트(track.js·워커)가 매번 다르게 파싱해야 한다.
	 * → docs/api-spec.md 8장
	 */
	protected function problem($status, $type, $detail, array $extra = array())
	{
		$body = array_merge(array(
			'type'     => 'https://'.(getenv('TRACK_DOMAIN') ?: 'example.invalid').'/problems/'.$type,
			'title'    => $type,
			'status'   => (int) $status,
			'detail'   => $detail,
			'trace_id' => $this->trace_id,
		), $extra);

		$this->output
			->set_status_header($status)
			->set_content_type('application/problem+json', 'utf-8')
			->set_header('Cache-Control: no-store')
			->set_output(json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
			->_display();

		exit(4);   // EXIT_UNKNOWN_FILE 아님 — 여기서 끝낸다는 뜻
	}

	/** JSON 성공 응답. */
	protected function json($status, array $body)
	{
		$this->output
			->set_status_header($status)
			->set_content_type('application/json', 'utf-8')
			->set_header('Cache-Control: no-store')
			->set_output(json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
	}

	/** $_SERVER 접근 한 곳으로. CLI 에서도 안전하게 빈 문자열이 나온다. */
	protected function server($key)
	{
		return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
	}
}
