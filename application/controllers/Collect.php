<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Http\CorsPolicy;

/**
 * 수집 API. `api.<도메인>` 에서만 열린다.
 *
 *   OPTIONS /collect   preflight
 *   POST    /collect   수집
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
		$policy = CorsPolicy::forSite(
			(string) getenv('SHOP_DOMAIN'),
			tp_env_bool('CORS_ALLOW_ORIGIN_WILDCARD')
		);

		$origin = $this->server('HTTP_ORIGIN');
		$method = strtoupper($this->server('REQUEST_METHOD'));

		if ($method === 'OPTIONS')
		{
			$this->respondPreflight($policy->preflight($origin, $this->server('HTTP_ACCESS_CONTROL_REQUEST_METHOD')));

			return;
		}

		$decision = $policy->actual($origin);
		$this->applyHeaders($decision->headers);

		if ( ! $decision->allowed)
		{
			log_message('error', 'collect 거부: '.$decision->reason.' origin='.$origin);
			$this->problem(403, 'origin-not-allowed', '허용되지 않은 오리진입니다.');
		}

		if ($method !== 'POST')
		{
			$this->problem(405, 'method-not-allowed', 'POST 만 받습니다.');
		}

		$this->store($origin);
	}

	// ────────────────────────────────────────────────────────

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
		$visitId = NULL;
		$uid = isset($body['visit_uid']) ? (string) $body['visit_uid'] : '';

		if ($uid !== '')
		{
			$visitId = $this->visit_model->findByUid($uid);
		}

		if ($visitId === NULL)
		{
			$visit = $this->visitor->current();
			$visitId = $visit['id'];
		}

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
