<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 랜딩.
 *
 *   GET /            진입 확인용
 *   GET /l/{work}    작품 랜딩. 브리지가 보내는 목적지
 *
 * 여기서도 유입을 기록한다. 브리지를 거치지 않고 직접 들어오는 경로가
 * 있기 때문이다 — 북마크, 검색 결과, 공유된 링크.
 * 그런 방문이 last-touch 를 덮어쓰지 않는다는 것은 TouchpointResolver 가 정한다.
 */
class Landing extends MY_Controller
{
	public function index()
	{
		$this->requireHost(array('lp', 'm'));
		$this->load->library('visitor');

		$visit = $this->visitor->current();
		$this->visitor->record();

		$this->load->view('landing/index', array(
			'read_target' => $this->readTarget(),
			'trace_id'    => $this->trace_id,
			'visit_uid'   => $visit['uid_hex'],
		));
	}

	/**
	 * 작품 랜딩.
	 *
	 * 지금은 회차 목록을 붙이지 않는다. 이 화면의 역할은 두 가지다 —
	 * 유입이 여기까지 이어졌는지 눈으로 확인하는 것, 그리고 track.js 를 싣는 것.
	 * 콘텐츠는 전환을 측정할 대상이 필요해서 두는 것이지 목적이 아니다.
	 */
	public function work($id = NULL)
	{
		$this->requireHost(array('lp', 'm'));

		if ($id === NULL OR preg_match('/\A[0-9]{1,18}\z/', (string) $id) !== 1)
		{
			$this->problem(404, 'not-found', '작품을 찾을 수 없습니다.');
		}

		$this->load->library('visitor');

		$visit  = $this->visitor->current();
		$result = $this->visitor->record();

		// 이 화면에서 무엇이 기록됐는지 그대로 보여준다.
		// 유입이 이어졌는지 확인하려고 DB 를 열어 보는 일을 없애기 위해서다.
		$touchpoints = $this->visit_model->touchpoints($visit['id']);

		// 수집 이벤트는 **배정받은 복제본에서** 읽는다.
		// 방금 track.js 가 보낸 건이 여기 안 보일 수 있다 — 그게 복제 지연이고,
		// 이 화면이 그걸 눈으로 보는 자리다 → docs/failure-scenarios.md E-1
		$this->load->model('collect_model');
		$events = $this->collect_model->recent($this->read(), $visit['id'], 10);

		$this->output->set_header('Cache-Control: no-store');
		$this->load->view('landing/work', array(
			'work'        => (string) $id,
			'visit_uid'   => $visit['uid_hex'],
			'is_new'      => $visit['is_new'],
			'result'      => $result,
			'touchpoints' => $touchpoints,
			'events'      => $events,
			'api_host'    => getenv('SHOP_DOMAIN') ? 'api.'.getenv('SHOP_DOMAIN') : '',
			'read_target' => $this->readTarget(),
			'trace_id'    => $this->trace_id,
		));
	}
}
