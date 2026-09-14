<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 지표 화면.
 *
 *   GET /metrics    app. 호스트에서만
 *
 * **이 화면의 규칙은 docs/benchmarks.md 5장에서 왔다.**
 *
 *   ① 채널별로 쪼갠다 — noop 과 ga4 를 한 숫자에 합치지 않는다.
 *      합쳤더니 9303건 중 9300건이 가짜 채널인 "성공률 100%" 가 나왔다
 *   ② 도달률(2xx 를 받았다)과 반영률(매체 보고서에 있다)을 다른 줄로 낸다.
 *      같은 전송을 두고 +0h 에는 100% 와 1% 가 동시에 참이었다(+40h 에 100% 로 수렴)
 *   ③ 분모를 숫자 옆에 같이 낸다. 20건짜리 100% 와 500건짜리 95% 는 무게가 다르다
 *
 * 인증은 붙이지 않는다(범위 밖). 대신 **식별자·회원 정보를 내리지 않는다** —
 * 내려가는 것은 건수·비율·구간 시간뿐이다. 인증이 없으니 화면이 담는 것을
 * 줄이는 쪽으로 막는다.
 *
 * gtag 파셜도 싣지 않는다. 운영자가 보는 화면의 page_view 가 측정 대상
 * 속성에 섞이면, 우리가 재려는 숫자를 우리가 흔드는 셈이다.
 */
class Metrics extends MY_Controller
{
	/**
	 * 이 수 미만이면 "표본 작음" 을 표시한다.
	 *
	 * 100건이면 1건이 1%p 를 움직인다. 목표가 99%대인 지표(도달률·반영률)에서
	 * 그 구간은 **한 건으로 합·불이 갈리는 구간**이라 비율만 보면 안 된다.
	 * 실제로 20건짜리 A·B군에서 ±1건이 5%p 를 흔들었다 → docs/benchmarks.md 5-1 ③
	 */
	const SMALL_SAMPLE = 100;

	/**
	 * 아무 데도 보내지 않는 채널.
	 *
	 * 이 줄의 "도달률" 은 매체에 대한 말이 아니다. 화면에서 눈에 띄게
	 * 갈라 두지 않으면 분모에 섞였던 그 실수를 화면이 다시 저지른다.
	 * 이름은 Channels::build() 의 match 절과 짝이다.
	 */
	const FAKE_CHANNELS = array('noop');

	public function index()
	{
		$this->requireHost('app');
		$this->load->model('metrics_model');

		/*
		 * 배정받은 복제본에서 읽는다.
		 *
		 * 판단 기준은 "직전에 내가 쓴 것을 읽는가" 인데(MY_Controller::read()),
		 * 지표는 방금 쓴 것을 되읽는 화면이 아니다. 복제 지연만큼 과거를
		 * 보는 것이 이 화면에서는 허용된다 — 대신 **어느 복제본에서 읽었는지**
		 * 화면에 적는다. 두 번 새로고침해서 숫자가 달라졌을 때, 그게 복제
		 * 지연인지 진짜 변화인지 가르는 단서가 그것뿐이다.
		 */
		$db = $this->read();

		$data = array(
			'outbox'      => $this->metrics_model->outboxByChannel($db),
			'dispatch'    => $this->metrics_model->dispatchByChannel($db),
			'statuses'    => $this->metrics_model->httpStatusByChannel($db),
			'attempts'    => $this->metrics_model->attemptsByChannel($db),
			'conversions' => $this->metrics_model->conversionsByType($db),
			'funnel'      => $this->metrics_model->visitFunnel($db),

			'outbox_statuses' => Metrics_model::OUTBOX_STATUSES,
			'small_sample'    => self::SMALL_SAMPLE,
			'fake_channels'   => self::FAKE_CHANNELS,

			'read_target' => $this->readTarget(),
			'trace_id'    => $this->trace_id,
			'now'         => tp_now_utc(),
		);

		// 캐시되면 화면의 시각과 숫자가 어긋난다. 지표 화면의 값어치는
		// "지금 무엇이 그런가" 이므로 중간 캐시를 허용하지 않는다.
		$this->output->set_header('Cache-Control: no-store');
		$this->load->view('metrics/index', $data);
	}
}
