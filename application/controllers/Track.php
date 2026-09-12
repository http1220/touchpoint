<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * `track.js` 서빙. `api.<도메인>` 에서만 연다.
 *
 * 왜 정적 파일로 두지 않는가
 *
 *   public/ 에 두면 nginx 가 모든 호스트에서 그대로 내준다. 그러면
 *   `lp.` 에서도 같은 파일이 나가고, 스크립트가 **자기 오리진을 수집
 *   주소로 삼기 때문에** 수집이 same-origin 이 되어 CORS 가 사라진다.
 *   실험 조건을 코드로 고정한다 → docs/decisions/ADR-017-ci3-application-structure.md
 *
 * 캐시
 *
 *   추적 스크립트는 자주 바뀌고, 낡은 버전이 남으면 수집 스키마가 갈린다.
 *   그렇다고 매 요청 내려받게 하면 랜딩이 느려진다. 짧은 max-age 에
 *   ETag 를 붙여 **바뀌지 않았으면 304** 로 끝낸다.
 */
class Track extends MY_Controller
{
	const PATH = APPPATH.'assets/track.js';

	/** 5분. 고치고 나서 반영까지 기다릴 수 있는 최대치로 잡았다. */
	const MAX_AGE = 300;

	public function js()
	{
		$this->requireHost('api');

		if ( ! is_file(self::PATH))
		{
			$this->problem(500, 'asset-missing', 'track.js 를 찾을 수 없습니다.');
		}

		$body = file_get_contents(self::PATH);
		$etag = '"'.substr(sha1($body), 0, 16).'"';

		$this->output
			->set_content_type('application/javascript', 'utf-8')
			->set_header('Cache-Control: public, max-age='.self::MAX_AGE)
			->set_header('ETag: '.$etag)
			// 스크립트는 오리진을 가리지 않고 내준다. 여기엔 비밀이 없고,
			// 어느 페이지에서든 로드될 수 있어야 한다.
			->set_header('Access-Control-Allow-Origin: *')
			->set_header('X-Content-Type-Options: nosniff');

		// 조건부 요청. 안 바뀌었으면 본문을 보내지 않는다.
		if (trim($this->server('HTTP_IF_NONE_MATCH')) === $etag)
		{
			$this->output->set_status_header(304);

			return;
		}

		$this->output->set_output($body);
	}
}
