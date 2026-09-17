<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Support\PublishDay;
use App\Support\SystemClock;

/**
 * 루트 도메인의 홈. 웹툰 서비스의 첫 화면이다.
 *
 *   GET https://<SHOP_DOMAIN>/
 *
 * ── 왜 이 화면이 있는가 ──
 *
 * 이 프로젝트는 어트리뷰션 파이프라인이고 웹툰 서비스가 아니다. 그런데
 * 전환을 측정하려면 **측정할 대상**이 필요하고, 그 대상이 어떻게 생겼는지를
 * 공개 자료로 역추론했다 → docs/research-method.md
 *
 * 이 화면은 그 역추론의 결과를 눈에 보이게 한 것이다. 뷰어도 검색도 없다.
 * **도메인 개념이 실제로 스키마에 들어가 있다는 것**만 보여 준다 —
 *
 *   작품이 연재요일을 **여럿** 갖는다        work_publish_days 가 별도 테이블
 *   기다리면 무료가 **작품** 속성이다        works.wait_free_hours
 *   무료/유료가 **회차** 속성이다            episodes.is_charged
 *   연령등급이 boolean 이 아니라 코드다      age_rating_code (국가별 등급 대응)
 *   언어·문자·지역이 다른 축이다             locales 의 lang/script/region
 *
 * **베낀 것이 아니다.** 제목·문구·배치는 전부 새로 만들었고, 가져온 것은
 * 위 다섯 줄뿐이다. 그 다섯은 남의 디자인이 아니라 도메인의 사실이다.
 *
 * ── 읽기는 복제본에서 ──
 *
 * 홈은 지연을 감수해도 되는 화면이다. 방금 올라온 회차가 몇 초 늦게
 * 보이는 것은 사고가 아니다 → ADR-007
 */
class Home extends MY_Controller
{
	public function index()
	{
		/*
		 * 루트 도메인에서만 연다.
		 *
		 * lp. 는 광고 랜딩이고 app. 은 서비스다. 홈이 세 곳에서 다 열리면
		 * 같은 내용이 세 오리진에 생겨 어느 것이 정본인지 알 수 없게 된다.
		 */
		$this->requireHost('root');

		$this->load->model('work_model');

		$db   = $this->read();
		$lang = 'ko';

		$this->output->set_header('Cache-Control: no-store');

		$this->load->view('home/index', array(
			'byDay'       => $this->work_model->byPublishDay($db, $lang),
			'top'         => $this->work_model->topByEpisodes($db, $lang, 5),
			'recent'      => $this->work_model->recentEpisodes($db, $lang, 8),
			'locales'     => $this->work_model->locales($db),
			'lang'        => $lang,
			// 연재 요일은 서비스 지역의 달력이다. UTC(gmdate)로 판정하면 한국 00~09시에 어제가 "오늘"이 된다
			'today'       => PublishDay::today(new SystemClock()),
			'read_target' => $this->readTarget(),
			'trace_id'    => $this->trace_id,
		));
	}
}
