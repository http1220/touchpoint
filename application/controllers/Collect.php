<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Collect\ClickRequest;
use App\Collect\ImpressionBatch;
use App\Http\CorsPolicy;

/**
 * 수집 API. `api.<도메인>` 에서만 열린다.
 *
 *   OPTIONS /collect      preflight
 *   POST    /collect      수집
 *   POST    /impression   노출 배치 (09-15)
 *   GET     /click        클릭 기록 후 302 (09-15)
 *
 * `lp.` → `api.` 는 **사이트는 같고 오리진은 다르다.** 쿠키는 `Lax` 로
 * 전송되고 CORS 는 그대로 걸린다 → docs/decisions/ADR-018-single-registered-domain.md
 *
 * CORS 판정은 src/Http/CorsPolicy.php 가 한다. 여기서는 헤더를 내보내고
 * 본문을 검증해 적재하는 일만 한다. 판정을 프레임워크 밖에 둔 이유는
 * 그래야 "닮은 오리진" 같은 경우를 테스트로 못박을 수 있어서다.
 */
class Collect extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->requireHost('api');
		$this->load->library('visitor');
		$this->load->model('collect_model');
	}

	public function index()
	{
		$origin = $this->corsGate('collect');

		if ($origin !== NULL)
		{
			$this->store($origin);
		}
	}

	/**
	 * `POST /impression` — 한 페이지에서 본 배너들을 한 요청으로.
	 *
	 * 규칙은 src/Collect/ImpressionBatch 에 있다. 대개 이탈할 때 sendBeacon 으로
	 * 오므로 **응답을 읽을 쪽이 없다.** 그래서 틀린 항목은 버리고 나머지를 받는다.
	 *
	 * 방문은 /collect 와 같은 순서로 찾는다(본문 visit_uid → 쿠키 → 새로 만듦).
	 * 노출은 페이지를 연 사람에게서만 오므로, 방문을 새로 만드는 경우가
	 * /click 보다 드물고 정당하다.
	 */
	public function impression()
	{
		$origin = $this->corsGate('impression');

		if ($origin === NULL)
		{
			return;
		}

		$body  = $this->body();
		$batch = ImpressionBatch::fromBody($body);

		if ( ! $batch->isValid())
		{
			$this->problem(422, 'invalid-request', $batch->reason, array('field' => 'items'));
		}

		$visitId  = $this->resolveVisitId($body);
		$inserted = $this->collect_model->recordImpressions(
			$visitId, $batch->items, $this->transport(), $origin, gmdate('Y-m-d')
		);

		log_message('info', sprintf('impression visit=%d accepted=%d dropped=%d transport=%s',
			$visitId, $inserted, $batch->dropped, $this->transport()));

		$this->json(200, array(
			'accepted' => $inserted,
			'dropped'  => $batch->dropped,
			'trace_id' => $this->trace_id,
		));
	}

	/**
	 * `GET /click?w=&s=&sd=&u=` — 기록하고 목적지로 302.
	 *
	 * GET 인데 상태를 바꾸는 이유와 그 대가를 막는 방법은 src/Collect/ClickRequest
	 * 머리말에 있다. 여기서 지키는 원칙은 하나다 —
	 *
	 *   **기록이 실패해도 이동은 실패하지 않는다.**
	 *
	 * 사용자는 배너를 눌렀고 작품을 보려는 것이다. 우리 집계 사정으로 그 이동을
	 * 막으면 안 된다. 그래서 봇·중복·방문 없음·DB 오류 모두 **302 는 나간다.**
	 * 이동을 막는 경우는 목적지가 틀렸을 때 하나뿐이다 — 거기로 보내면 오픈
	 * 리다이렉트가 된다.
	 *
	 * CORS 가 없다. 링크 이동은 교차 출처 요청이 아니다.
	 */
	public function click()
	{
		$method = strtoupper($this->server('REQUEST_METHOD'));

		if ($method !== 'GET' && $method !== 'HEAD')
		{
			$this->problem(405, 'method-not-allowed', 'GET 만 받습니다.');
		}

		$req = ClickRequest::fromQuery(
			// xss_clean 을 끈다. 목적지 URL 의 쿼리를 변형하고, 검증은 ClickRequest 가 엄격하게 한다
			$this->input->get(NULL, FALSE) ?: array(),
			(string) $this->input->user_agent(),
			(string) (getenv('SHOP_DOMAIN') ?: ''),
			new DateTimeImmutable('now', new DateTimeZone('UTC'))
		);

		if ($req->destination === NULL)
		{
			$this->problem(400, 'invalid-destination', '이동할 주소가 이 사이트의 https 주소가 아닙니다.', array('field' => 'u'));
		}

		// HEAD 는 링크 검사기가 쓴다. 이동만 알려 주고 세지 않는다.
		if ($method === 'GET' && $req->shouldRecord())
		{
			$this->recordClick($req);
		}

		$this->output
			->set_status_header(302)
			// 중간 캐시가 302 를 재사용하면 두 번째 클릭부터 우리에게 오지 않는다.
			->set_header('Cache-Control: no-store')
			// 추적 주소가 검색 결과에 목적지 대신 뜨지 않게.
			->set_header('X-Robots-Tag: noindex, nofollow')
			->set_header('Location: '.$req->destination);
	}

	// ────────────────────────────────────────────────────────

	/**
	 * CORS 판정 · preflight 응답 · POST 확인. 통과하면 Origin 을, 이미 응답했으면 NULL.
	 *
	 * /collect 에 있던 것을 /impression 과 나누려고 꺼냈다. 두 경로의 CORS 가
	 * 달라지면 track.js 의 한쪽만 조용히 막힌다.
	 */
	private function corsGate($label)
	{
		$policy = CorsPolicy::forSite(
			(string) getenv('SHOP_DOMAIN'),
			tp_env_bool('CORS_ALLOW_ORIGIN_WILDCARD')
		);

		$origin = $this->server('HTTP_ORIGIN');
		$method = strtoupper($this->server('REQUEST_METHOD'));

		if ($method === 'OPTIONS')
		{
			$this->respondPreflight($policy->preflight($origin, $this->server('HTTP_ACCESS_CONTROL_REQUEST_METHOD')));

			return NULL;
		}

		$decision = $policy->actual($origin);
		$this->applyHeaders($decision->headers);

		if ( ! $decision->allowed)
		{
			log_message('error', $label.' 거부: '.$decision->reason.' origin='.$origin);
			$this->problem(403, 'origin-not-allowed', '허용되지 않은 오리진입니다.');
		}

		if ($method !== 'POST')
		{
			$this->problem(405, 'method-not-allowed', 'POST 만 받습니다.');
		}

		return $origin;
	}

	/** 본문 visit_uid → 쿠키 → 새 방문. /collect 와 /impression 이 같은 순서다. */
	private function resolveVisitId(array $body)
	{
		$uid = isset($body['visit_uid']) ? (string) $body['visit_uid'] : '';

		if ($uid !== '')
		{
			$id = $this->visit_model->findByUid($uid);

			if ($id !== NULL)
			{
				return $id;
			}
		}

		$visit = $this->visitor->current();

		return (int) $visit['id'];
	}

	/**
	 * 클릭 기록. **방문 쿠키가 있을 때만.**
	 *
	 * Visitor::current() 는 없으면 새 방문을 만든다. 여기서 만들면
	 * landing_path 가 /click 인, 유입 접점 없는 방문이 생긴다 — 쿠키 없는
	 * 클릭은 대개 링크를 복사해 붙인 경우나 쿠키를 막은 브라우저라, 그 방문은
	 * 어트리뷰션 분모만 오염시킨다(Conversion.php 와 같은 판단).
	 */
	private function recordClick(ClickRequest $req)
	{
		$cookie = $this->input->cookie(getenv('COOKIE_VID_NAME') ?: 'ab_vid', TRUE);
		$visitId = is_string($cookie) ? $this->visit_model->findByUid($cookie) : NULL;

		if ($visitId === NULL)
		{
			log_message('info', 'click 방문 없음 — 기록 없이 이동 w='.$req->workId.' s='.$req->slot);

			return;
		}

		$new = $this->collect_model->recordClick($visitId, $req->workId, $req->slot, $req->statDate, $req->dedupKey($visitId));

		log_message('info', sprintf('click %s visit=%d w=%d s=%s sd=%s',
			$new ? 'new' : 'dup', $visitId, $req->workId, $req->slot, $req->statDate));
	}

	private function respondPreflight($decision)
	{
		$this->applyHeaders($decision->headers);

		// preflight 응답에는 본문이 없다. 204 다.
		$this->output->set_status_header($decision->status);

		if ( ! $decision->allowed)
		{
			log_message('error', 'preflight 거부: '.$decision->reason);
		}
	}

	private function store($origin)
	{
		$body = $this->body();

		$event = isset($body['event']) ? (string) $body['event'] : '';

		if ( ! in_array($event, Collect_model::EVENTS, TRUE))
		{
			$this->problem(422, 'unknown-event', '모르는 이벤트입니다: '.$event);
		}

		/*
		 * 방문을 찾는 순서가 중요하다.
		 *
		 * 본문의 visit_uid 를 먼저 보고, 없으면 쿠키를 본다.
		 * sendBeacon 은 커스텀 헤더를 못 붙이고, 이탈 직전에 나가기 때문에
		 * 쿠키가 실리지 않는 경우가 있다. 그때 본문이 유일한 단서다.
		 */
		$visitId = $this->resolveVisitId($body);

		$id = $this->collect_model->record(array(
			'visit_id'    => $visitId,
			'event'       => $event,
			'work_id'     => isset($body['work_id']) && ctype_digit((string) $body['work_id'])
				? (string) $body['work_id'] : NULL,
			'transport'   => $this->transport(),
			'origin'      => $origin,
			'occurred_at' => $this->occurredAt($body),
		));

		log_message('info', sprintf('collect event=%s visit=%d transport=%s id=%d',
			$event, $visitId, $this->transport(), $id));

		$this->json(200, array('ok' => TRUE, 'trace_id' => $this->trace_id));
	}

	/**
	 * 본문 파싱.
	 *
	 * `sendBeacon` 은 Content-Type 을 text/plain 으로 보낸다 — preflight 를
	 * 피하려고 그렇게 만들어져 있다. 그래서 Content-Type 으로 형식을
	 * 판단하지 않고 **본문을 JSON 으로 파싱해 본다.**
	 */
	private function body()
	{
		$raw = $this->input->raw_input_stream;

		if ( ! is_string($raw) || $raw === '')
		{
			return array();
		}

		$parsed = json_decode($raw, TRUE);

		return is_array($parsed) ? $parsed : array();
	}

	/** fetch 로 왔는지 sendBeacon 으로 왔는지. Content-Type 이 유일한 단서다. */
	private function transport()
	{
		$type = strtolower($this->server('CONTENT_TYPE'));

		if ($type === '')
		{
			return 'server';
		}

		return strpos($type, 'application/json') !== FALSE ? 'fetch' : 'beacon';
	}

	/**
	 * 클라이언트가 주장하는 발생 시각.
	 *
	 * 믿지 않는다 — 기기 시계는 틀어져 있고 조작도 된다. 그래서 received_at
	 * 을 따로 남기고, 형식이 이상하면 서버 시각으로 대체한다.
	 */
	private function occurredAt(array $body)
	{
		$raw = isset($body['occurred_at']) ? (string) $body['occurred_at'] : '';

		if ($raw === '')
		{
			return tp_now_utc();
		}

		try
		{
			$t = new DateTimeImmutable($raw);
		}
		catch (Exception $e)
		{
			return tp_now_utc();
		}

		return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
	}

	/** @param array<string,string> $headers */
	private function applyHeaders(array $headers)
	{
		foreach ($headers as $name => $value)
		{
			$this->output->set_header($name.': '.$value);
		}

		$this->output->set_header('Cache-Control: no-store');
	}
}
