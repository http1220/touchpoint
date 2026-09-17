<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 시연 안내.
 *
 *   GET https://<SHOP_DOMAIN>/tour
 *
 * 홈에 있던 안내를 여기로 옮겼다. 홈은 **측정 대상인 서비스**로 보여야 하고,
 * "이건 시연입니다" 라는 말은 서비스 화면이 할 말이 아니다.
 *
 * DB 를 읽지 않는다 — 파이프라인의 모양과 링크뿐이다.
 */
class Tour extends MY_Controller
{
	public function index()
	{
		// 홈과 같은 자리다. 안내가 lp.·app. 에서도 열리면 어느 것이 정본인지 흐려진다.
		$this->requireHost('root');

		$this->output->set_header('Cache-Control: no-store');

		$this->load->view('tour/index', array(
			'trace_id' => $this->trace_id,
		));
	}
}
