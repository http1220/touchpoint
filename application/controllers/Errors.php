<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 404_override.
 *
 * 대상 조직은 없는 경로에 200 + 홈 화면을 돌려준다(소프트 404).
 * 여기서는 따라하지 않는다 — 404 비율이 장애 신호로 안 쓰이게 되기 때문이다.
 * 같은 판단을 nginx 쪽에도 적어 뒀다. → docker/openresty/conf.d/php-app.conf
 */
class Errors extends MY_Controller
{
	public function not_found()
	{
		// 수집 API 는 JSON 을 기대한다. 사람이 보는 화면은 HTML 이어야 한다.
		$accept = strtolower($this->server('HTTP_ACCEPT'));

		if (strpos($accept, 'application/json') !== FALSE OR $this->input->is_ajax_request())
		{
			$this->problem(404, 'not-found', '없는 경로입니다.');
		}

		$this->output
			->set_status_header(404)
			->set_content_type('text/html', 'utf-8');
		$this->load->view('errors/not_found');
	}
}
