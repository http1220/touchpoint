<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Attribution\ConversionInput;
use App\Channel\GaClientId;
use App\Http\CorsPolicy;

/**
 * 전환 등록 API. `api.<도메인>` 에서만 열린다.
 *
 *   OPTIONS /conversion   preflight
 *   POST    /conversion   전환 기록 + 아웃박스 적재 (한 트랜잭션)
 *
 * CORS 는 /collect 와 **같은 CorsPolicy** 를 쓴다. 두 엔드포인트가 같은
 * 호스트에 있고 같은 오리진들에서 불리므로, 정책을 따로 두면 한쪽만
 * 고쳐지는 날이 온다 → docs/decisions/ADR-018-single-registered-domain.md
 *
 * 이 컨트롤러의 핵심은 중복 처리다. **같은 dedup_key 재요청은 에러가
 * 아니라 200 + 기존 conversion_uid** 다. 사용자가 결제 버튼을 두 번
 * 누르거나 클라이언트가 타임아웃 후 재시도하는 것은 정상 동작이고,
 * 409 로 만들면 호출자가 "이 에러는 사실 성공" 이라는 예외 처리를
 * 떠안는다 → docs/api-spec.md 4장
 *
 * 판정은 전부 밖에 있다 — CORS 는 src/Http/CorsPolicy, 본문 검증은
 * src/Attribution/ConversionInput, 중복은 DB 의 UNIQUE. 여기서는
 * 그것들을 잇고 응답 모양을 만든다 → ADR-017
 */
class Conversion extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->requireHost('api');
		$this->load->library('visitor');   // 생성자에서 visit_model 을 함께 올린다
		$this->load->library('channels');
		$this->load->model('conversion_model');
	}

	public function index()
	{
		/*
		 * CORS 배관이 Collect::index() 와 같은 모양이다.
		 *
		 * MY_Controller 로 끌어올리지 않고 둔다 — 두 엔드포인트뿐이고,
		 * 기반 층에 올리면 "이 경로는 CORS 를 쓰는가" 가 컨트롤러를 읽어서는
		 * 안 보이게 된다. 세 번째가 생기면 그때 옮긴다.
		 */
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
			log_message('error', 'conversion 거부: '.$decision->reason.' origin='.$origin);
			$this->problem(403, 'origin-not-allowed', '허용되지 않은 오리진입니다.');
		}

		if ($method !== 'POST')
		{
			$this->problem(405, 'method-not-allowed', 'POST 만 받습니다.');
		}

		$this->register();
	}

	// ────────────────────────────────────────────────────────

	private function register()
	{
		$input = ConversionInput::fromBody($this->body());

		if ( ! $input->isValid())
		{
			/*
			 * 문제 유형은 docs/api-spec.md 8장 표대로 invalid-request 하나로
			 * 두고, 어느 필드가 걸렸는지는 RFC 9457 확장 멤버로 붙인다.
			 * 필드마다 type URI 를 쪼개면 클라이언트가 URI 목록을 따라다녀야
			 * 하는데, 여기서 필요한 분기는 "고쳐서 다시 보내라" 하나뿐이다.
			 */
			$this->problem(422, 'invalid-request', $input->reason, array('field' => $input->invalidField));
		}

		$visit = $this->resolveVisit($input->visitUid);
		$names = $this->channels->names();

		if ($names === array())
		{
			/*
			 * 채널이 없어도 전환은 기록한다.
			 *
			 * .env 설정이 빠졌다고 전환을 거절하면 나중에 채워 넣을 원본이
			 * 남지 않는다. 아웃박스만 비는 것은 conversions 를 읽어 다시
			 * 적재할 수 있다 — 되돌릴 수 있는 쪽으로 넘어진다.
			 */
			log_message('error', 'conversion: CHANNELS 가 비어 있어 아웃박스에 적재하지 않는다.');
		}

		$result = $this->conversion_model->createWithOutbox(array(
			'visit_id'    => $visit === NULL ? NULL : $visit['id'],
			'type'        => $input->type,
			'value_minor' => $input->valueMinor,
			'currency'    => $input->currency,
			'dedup_key'   => $input->dedupKey,
			'client_id'   => $this->clientId($visit),

			/*
			 * user_id 는 넘기지 않는다 — user_uid(BINARY(16)) 를 users.id 로
			 * 바꿀 조회 경로가 아직 없다. conversions.user_id 는 NULL 로 남고,
			 * 식별자는 아웃박스 payload 의 user_uid 로만 매체에 나간다
			 * (Ga4Channel 이 GA4 의 user_id 로 쓴다).
			 * /signup·/purchase 가 붙을 때 함께 채워야 할 자리다.
			 */
			'user_uid'    => $input->userUid === NULL ? '' : $input->userUid,
		), $names);

		if ($result['duplicated'])
		{
			$this->respondDuplicate($input->dedupKey);

			return;
		}

		if ($result['enqueued'] !== count($names))
		{
			// UNIQUE (conversion_id, channel) 에 걸린 것 말고는 이유가 없다.
			// 신규 전환에서 그게 나온다면 채널 이름이 중복된 것이다.
			log_message('error', sprintf('conversion %s: 채널 %d개 중 %d개만 적재됐다.',
				$result['uid_hex'], count($names), $result['enqueued']));
		}

		log_message('info', sprintf('conversion new uid=%s type=%s visit=%s channels=%d',
			$result['uid_hex'], $input->type,
			$visit === NULL ? '-' : (string) $visit['id'], $result['enqueued']));

		$this->json(201, array(
			'conversion_uid'      => $result['uid_hex'],

			// "보냈다" 가 아니라 "보내기로 적재했다" 이다. 실제 전송은
			// 워커가 하고, 결과는 dispatch_outbox 에 남는다 → ADR-003
			'dispatched_channels' => $names,
			'duplicated'          => FALSE,
			'trace_id'            => $this->trace_id,
		));
	}

	/**
	 * 같은 dedup_key 로 다시 들어온 요청.
	 *
	 * **200 이다.** 호출자가 받아야 할 것은 "이미 처리됐다 + 그때의
	 * conversion_uid" 이고, 그것만 있으면 재시도 루프를 끝낼 수 있다.
	 *
	 * dispatched_channels 가 비는 이유: 모델이 롤백했으므로 이번 요청으로는
	 * 전환도 아웃박스도 아무것도 늘지 않았다. 처음 요청이 적재한 것은
	 * 그대로 살아 있다.
	 */
	private function respondDuplicate($dedupKey)
	{
		$existing = $this->conversion_model->findByDedupKey($dedupKey);

		if ($existing === NULL)
		{
			/*
			 * UNIQUE 에는 걸렸는데 행이 없다.
			 *
			 * INSERT IGNORE 가 0 을 돌려줬다는 것은 충돌한 행이 커밋돼
			 * 있었다는 뜻이다(커밋 전이면 잠금에서 기다린다). 그러고도 못
			 * 찾는 경우는 둘이다 — 그 사이 파기 배치가 지웠거나, 걸린 것이
			 * dedup_key 가 아니라 uq_conv_uid 이거나. 후자면 UUIDv7 이
			 * 겹쳤다는 말이라 버그다.
			 *
			 * 어느 쪽이든 없는 uid 를 지어내 200 을 줄 수는 없다. 여기서만
			 * 409 를 쓴다 — "중복이라 성공" 과 구분되는 진짜 충돌이다.
			 */
			log_message('error', 'conversion: 중복 판정인데 기존 행을 못 찾았다. dedup_key='.$dedupKey);
			$this->problem(409, 'conversion-conflict', '중복으로 판정됐지만 기존 전환을 찾지 못했습니다.');
		}

		log_message('info', 'conversion dup uid='.$existing['uid_hex'].' dedup_key='.$dedupKey);

		$this->json(200, array(
			'conversion_uid'      => $existing['uid_hex'],
			'dispatched_channels' => array(),
			'duplicated'          => TRUE,
			'trace_id'            => $this->trace_id,
		));
	}

	/**
	 * 이 전환에 붙일 방문.
	 *
	 * 본문의 visit_uid 가 먼저다. 전환은 결제 완료 화면이나 서버 콜백에서
	 * 오므로 쿠키가 실리지 않는 경로가 있고, 그때 본문이 유일한 단서다.
	 *
	 * **visit_uid 를 줬는데 못 찾으면 404 다.** /collect 는 같은 상황에서
	 * 쿠키로 넘어가는데, 여기서는 판단이 갈린다 — 조용히 다른 방문에 붙이면
	 * 호출자는 자기가 지정한 방문에 기록된 줄 알고, 매출이 엉뚱한 매체에
	 * 귀속된다. 틀린 채로 성공하는 것이 실패하는 것보다 나쁘다.
	 * → docs/api-spec.md 8장의 visit-not-found
	 *
	 * @return array|null [id, uid_hex, …]
	 */
	private function resolveVisit($visitUid)
	{
		if ($visitUid !== NULL)
		{
			$id = $this->visit_model->findByUid($visitUid);

			if ($id === NULL)
			{
				$this->problem(404, 'visit-not-found', '그런 방문이 없습니다: '.$visitUid);
			}

			return array('id' => $id, 'uid_hex' => $visitUid);
		}

		/*
		 * 쿠키가 있을 때만 Visitor 를 부른다.
		 *
		 * Visitor::current() 는 방문을 못 찾으면 **새로 만든다.** 랜딩에서는
		 * 그게 맞지만 여기서 만들면 landing_path 가 /conversion 인 방문이
		 * 생긴다 — 유입 접점이 하나도 없는 방문이 분모에 들어가서
		 * 어트리뷰션 보존율이 실제보다 낮게 나온다. 측정하려고 만든 지표를
		 * 측정 행위가 망가뜨리는 셈이다 → docs/api-spec.md 7장
		 *
		 * 쿠키가 아예 없으면 방문 없이 기록한다. conversions.visit_id 는
		 * NULL 을 허용한다 — 유입을 모르는 전환도 매출은 매출이다.
		 */
		$name = getenv('COOKIE_VID_NAME') ?: 'ab_vid';

		if ( ! is_string($this->input->cookie($name, TRUE)))
		{
			return NULL;
		}

		return $this->visitor->current();
	}

	/**
	 * GA4 Measurement Protocol 의 client_id.
	 *
	 * gtag 가 브라우저에 심은 `_ga` 쿠키에서 뽑는다. 등록 도메인이 하나라
	 * lp. 에서 심은 쿠키가 api. 에도 실려 온다 → ADR-018
	 *
	 * 못 얻으면 방문 식별자로 대체값을 만든다. **브라우저 세션과는 이어지지
	 * 않아 GA4 에서 신규 사용자로 잡히지만**, 전환 건수 자체는 남는다.
	 * 대체를 썼다는 사실을 로그로 남기는 것이 이 분기의 값이다 —
	 * 나중에 GA4 수치와 내부 수치가 어긋날 때 여기부터 본다.
	 */
	private function clientId($visit)
	{
		$fromCookie = GaClientId::fromCookie((string) $this->input->cookie('_ga', TRUE));

		if ($fromCookie !== NULL)
		{
			return $fromCookie;
		}

		if ($visit === NULL)
		{
			/*
			 * 쿠키도 방문도 없다. 형식만 맞춘 대체값조차 만들 수 없다 —
			 * 씨앗이 없으면 요청마다 값이 달라져서 한 사람이 여럿으로
			 * 쪼개진다. 빈 값으로 두고 기록만 남긴다. GA4 는 깨진
			 * 페이로드에도 2xx 를 주므로 이 로그가 유일한 단서다.
			 */
			log_message('error', 'conversion: _ga 쿠키도 방문도 없어 client_id 가 빈다.');

			return '';
		}

		log_message('info', 'conversion: _ga 없음 — visit '.$visit['uid_hex'].' 로 client_id 대체값을 만든다.');

		// 최초 방문 시각은 UUIDv7 안에 들어 있다. DB 를 한 번 더 읽지 않는다.
		return GaClientId::fallback($visit['uid_hex'], tp_uuid7_unix($visit['uid_hex']));
	}

	/**
	 * 본문 파싱.
	 *
	 * Content-Type 을 보지 않고 JSON 으로 파싱해 본다. /collect 와 같은
	 * 이유는 아니다 — 여기로 sendBeacon 이 오지는 않는다. 다만 서버 간
	 * 호출자(결제 웹훅 처리기)가 헤더를 빠뜨리는 일이 잦고, 그걸
	 * 415 로 돌려보내면 전환 한 건이 사라진다.
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

	private function respondPreflight($decision)
	{
		$this->applyHeaders($decision->headers);

		// preflight 응답에는 본문이 없다. 204 다.
		$this->output->set_status_header($decision->status);

		if ( ! $decision->allowed)
		{
			log_message('error', 'conversion preflight 거부: '.$decision->reason);
		}
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
