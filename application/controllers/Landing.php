<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 랜딩. 기본 컨트롤러.
 *
 * 지금은 뼈대만 있다. 작품 랜딩(/l/{work})과 유입 파라미터 적재는
 * 수집 파이프라인과 함께 붙인다. → docs/roadmap.md Phase 1
 */
class Landing extends MY_Controller
{
	public function index()
	{
		$this->load->view('landing/index', array(
			'read_target' => $this->readTarget(),
			'trace_id'    => $this->trace_id,
		));
	}
}
