<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Payment\PaymentStatus;
use App\Payment\WebhookEvent;
use App\Payment\WebhookSignature;

/**
 * PG 웹훅 수신. `app.<도메인>` 에서만 열린다.
 *
 *   POST /webhooks/pg
 *
 * `api.` 가 아니라 `app.` 인 이유 → docs/plan-payment-webhook.md 12장 결정 1
 * `api.` 는 브라우저 수집 전용이고 CorsPolicy 가 붙어 있다. 서버 간 호출인
 * 웹훅을 거기 두면 A-3·B-1 의 CORS 측정에 성격이 다른 트래픽이 섞인다.
 *
 * **CORS 헤더를 내보내지 않는다.** 브라우저가 부르는 경로가 아니다.
 * preflight 도 없다 — 서버 간 호출에는 오리진이 없다.
 *
 * ── 이 컨트롤러의 핵심은 응답 코드다 ──
 *
 * 무시(과거 상태·이미 같은 상태)도 **200** 이다. 2xx 가 아니면 PG 가
 * 영원히 재전송한다. "우리는 이 이벤트를 처리했고 더 보낼 필요 없다" 를
 * 뜻하는 코드가 200 이고, **무시는 성공적인 처리다.**
 *
 * 모르는 payment_uid 만 404 로 낸다 — 우리 쪽 커밋이 늦었을 가능성이
 * 남아 있어 재전송을 유도하는 편이 낫다.
 */
class Webhook extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->requireHost('app');
		$this->load->model('payment_model');
	}

	public function pg()
	{
		if (strtoupper($this->server('REQUEST_METHOD')) !== 'POST')
		{
			$this->problem(405, 'method-not-allowed', 'POST 만 받습니다.');
		}

		/*
		 * 원본 바이트를 먼저 잡는다. 파싱하기 전이다.
		 *
		 * 서명은 이 바이트열에 걸려 있다. json_decode 후 다시 encode 하면
		 * 키 순서·공백 하나로 서명이 깨진다 → src/Payment/WebhookSignature ①
		 */
		$raw = $this->input->raw_input_stream;
		$raw = is_string($raw) ? $raw : '';

		// ── ① 서명. 본문 해석보다 먼저다 ──
		$verdict = (new WebhookSignature(
			(string) (getenv('PG_WEBHOOK_SECRET') ?: ''),
			(int) (getenv('PG_WEBHOOK_TOLERANCE_SEC') ?: WebhookSignature::DEFAULT_TOLERANCE_SEC)
		))->verify($this->server('HTTP_X_PG_SIGNATURE'), $raw, time());

		if ( ! $verdict->ok)
		{
			/*
			 * 이유는 로그에만 남긴다. 응답은 invalid-signature 하나다 —
			 * "시각 창 초과" 와 "서명 불일치" 를 구분해 주면 공격자에게
			 * 재생 창의 크기를 알려 준다 → src/Payment/SignatureVerdict
			 */
			log_message('error', 'webhook 서명 거절: '.$verdict->reason);
			$this->problem(401, 'invalid-signature', '서명을 확인할 수 없습니다.');
		}

		// ── ② 본문 ──
		$parsed = json_decode($raw, TRUE);
		$event  = WebhookEvent::fromBody(is_array($parsed) ? $parsed : array());

		if ( ! $event->isValid())
		{
			// 재전송해도 같은 결과다. PG 에게 "그만 보내라" 를 알리는 4xx.
			$this->problem(422, 'invalid-request', $event->reason, array('field' => $event->invalidField));
		}

		// ── ③ 대상 ──
		$payment = $this->payment_model->findByUid($event->paymentUid);

		if ($payment === NULL)
		{
			/*
			 * 404 다. 여기만 재전송을 유도한다.
			 *
			 * PG 가 우리보다 빨랐을 수 있다 — /purchase 의 커밋이 늦었거나,
			 * 복제 지연을 타는 경로였거나. 4xx 로 끊어 버리면 그 결제는
			 * 영원히 captured 가 되지 않는다.
			 */
			log_message('error', 'webhook 대상 없음: payment_uid='.$event->paymentUid);
			$this->problem(404, 'payment-not-found', '그런 결제가 없습니다.');
		}

		// ── ④ 전이 ──
		$r = $this->payment_model->applyEvent($payment, $event->status, $raw);

		/*
		 * 적용되지 않았는데 **재전송이 필요한** 경우. 200 을 주면 PG 가 멈춘다.
		 *
		 *   refund-before-capture  409  캡처가 아직 안 왔다. 캡처 뒤 재전송에서 처리된다
		 *   db-error · payment-vanished  503  롤백했다. 다시 보내면 다시 기회가 온다
		 *
		 * 뒤의 둘은 09-15 까지 **200 ignored 로 나가고 있었다.** 모델 주석은
		 * "롤백하면 PG 가 재전송한다" 고 적었지만, 컨트롤러가 error 를 보지 않아
		 * PG 는 성공으로 받고 끝냈다 — 약속과 구현이 어긋난 또 한 자리.
		 */
		if ($r['error'] === 'refund-before-capture')
		{
			$this->problem(409, 'payment-not-captured', '아직 캡처되지 않은 결제의 환불입니다. 캡처 뒤 다시 보내 주세요.', array('status' => $r['status']));
		}

		if ($r['error'] !== NULL)
		{
			log_message('error', 'webhook 처리 실패 — 재전송 요청: '.$r['error'].' payment='.$event->paymentUid);
			$this->output->set_header('Retry-After: 30');
			$this->problem(503, 'temporarily-unavailable', '일시적으로 처리하지 못했습니다. 다시 보내 주세요.');
		}

		log_message('info', sprintf(
			'webhook %s payment=%s %s→%s event_id=%s%s',
			$r['applied'] ? 'applied' : 'ignored',
			$event->paymentUid, $r['from'], $r['status'], $event->eventId,
			isset($r['conversion_uid']) && $r['conversion_uid'] !== NULL
				? ' conversion='.$r['conversion_uid'] : ''
		));

		/*
		 * applied 든 ignored 든 200 이다.
		 *
		 * 호출자가 알아야 할 것은 "재전송이 필요한가" 뿐이고, 둘 다 답은
		 * '아니오' 다. 어느 쪽이었는지는 result 로 구분해 준다 —
		 * 상태 코드로 구분하면 PG 가 ignored 를 실패로 읽는다.
		 */
		$body = array(
			'result'  => $r['applied'] ? 'applied' : 'ignored',
			'status'  => $r['status'],
			'trace_id' => $this->trace_id,
		);

		if ($r['applied'] && $r['status'] === PaymentStatus::REFUNDED)
		{
			// 매체 환불 전환(없으면 null — 가리킬 구매가 없었다)과 코인 회수 결과.
			// coins_spent > 0 이면 약관 조건을 넘어선 환불이라 사람이 봐야 한다.
			$body['refund_conversion_uid'] = $r['conversion_uid'];
			$body['coins_revoked']         = $r['coins_revoked'];
			$body['coins_spent']           = $r['coins_spent'];
		}

		if ($r['applied'] && $r['status'] === PaymentStatus::CAPTURED)
		{
			// 전환이 적재됐다는 사실만 알린다. 실제 전송은 워커가 한다.
			$body['conversion_uid'] = $r['conversion_uid'];
		}

		$this->json(200, $body);
	}
}
