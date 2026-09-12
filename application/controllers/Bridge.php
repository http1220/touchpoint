<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Attribution\BridgeDestination;

/**
 * 브리지. 광고에서 들어온 클릭을 기록하고 랜딩으로 넘긴다.
 *
 *   GET /go?work=8733&pid=google&utm_source=google&gclid=...
 *
 * 대상 조직의 /webtoon/bridge/type/2/toon/8733 → /webtoon/detail/... 패턴을
 * 그대로 따른다. 왜 굳이 한 번 거쳐 가는가 — 그 한 번이 기록할 기회다.
 * 목적지로 바로 보내면 클릭이 서버에 남지 않는다.
 *
 * → docs/api-spec.md 1장
 */
class Bridge extends MY_Controller
{
	public function go()
	{
		// 모바일 UA 는 엣지에서 m. 으로 한 번 튕긴 뒤 여기 온다.
		// 그래서 두 호스트를 모두 허용한다 → docker/openresty/nginx.conf
		$this->requireHost(array('lp', 'm'));

		$this->load->library('visitor');

		$visit  = $this->visitor->current();
		$result = $this->visitor->record();

		$query = $this->input->get();
		$query = is_array($query) ? $query : array();

		// 모드는 요청이 이기고, 없으면 .env 의 기본값을 쓴다.
		// 한 브라우저에서 두 방식을 번갈아 볼 수 있어야 비교가 된다.
		$mode = BridgeDestination::normalizeMode(
			$this->input->get('mode') ?: getenv('BRIDGE_PARAM_MODE')
		);

		// 온 호스트로 되돌린다. 모바일이 m. 으로 왔는데 lp. 로 보내면
		// 엣지가 다시 m. 으로 튕겨 302 가 한 단 더 늘어난다.
		$base = 'https://'.$this->server('HTTP_HOST');

		try
		{
			$location = BridgeDestination::build(
				$base,
				(string) $this->input->get('work'),
				$visit['uid_hex'],
				$query,
				$mode
			);
		}
		catch (InvalidArgumentException $e)
		{
			$this->problem(400, 'invalid-target', '목적지를 만들 수 없습니다: '.$e->getMessage());
		}

		log_message('info', sprintf(
			'bridge work=%s mode=%s visit=%d direct=%s first=%s last=%s',
			(string) $this->input->get('work'), $mode,
			$result['visit_id'], $result['is_direct'] ? 'y' : 'n',
			$result['first'], $result['last']
		));

		/*
		 * 301 이 아니라 302 인 이유. 재 봤다 → docs/failure-scenarios.md C-1
		 *
		 * 캐시 헤더 없는 301 로 내보내니 같은 링크를 세 번 눌러도
		 * 서버에는 한 번만 왔다. 나머지 두 번은 브라우저 캐시에서 끝났다.
		 *
		 * 진짜 문제는 그 다음이었다. 302 + no-store 로 되돌린 뒤
		 * 같은 URL 을 두 번 더 눌렀는데 **한 번도 서버에 오지 않았다.**
		 * 고쳐도 이미 캐시한 브라우저는 돌아오지 않는다 — 보통의 사고와
		 * 달리 301 은 롤백이 없다. 그래서 애초에 내보내지 않는다.
		 *
		 * 301 자체가 범인은 아니다. no-store 를 붙인 301 은 매번 서버에
		 * 왔다. 위험한 것은 **영구 캐시를 허용하는 301** 이다.
		 *
		 * .env 의 BRIDGE_REDIRECT_STATUS 로 그 증상을 재현할 수 있게 열어 뒀다.
		 */
		$status = (int) (getenv('BRIDGE_REDIRECT_STATUS') ?: 302);
		$status = in_array($status, array(301, 302, 303, 307, 308), TRUE) ? $status : 302;

		/*
		 * 캐시 헤더를 끌 수 있게 열어 둔다.
		 *
		 * 301 이 위험한 진짜 이유를 가르려면 세 경우를 나눠 봐야 한다.
		 *
		 *   302 + no-store   매번 서버에 온다 (기본값)
		 *   301 + no-store   no-store 가 이기는가?
		 *   301 + 헤더 없음  브라우저가 영구 캐시한다
		 *
		 * 세 번째가 광고 링크에서 사고가 나는 자리다. 목적지를 바꿔도
		 * 반영되지 않고, 두 번째 클릭부터는 요청이 서버에 오지 않아
		 * 클릭 집계가 통째로 사라진다. → docs/failure-scenarios.md C-1
		 */
		$cache = getenv('BRIDGE_CACHE_CONTROL');
		$cache = ($cache === FALSE) ? 'no-store' : trim($cache);

		$this->output
			->set_status_header($status)
			->set_header('Location: '.$location);

		if ($cache === '')
		{
			/*
			 * PHP 세션이 이미 캐시 금지 헤더를 붙여 놨다.
			 *
			 *   session.cache_limiter = nocache   (PHP 기본값)
			 *   → Expires: Thu, 19 Nov 1981 08:52:00 GMT
			 *     Cache-Control: no-store, no-cache, must-revalidate
			 *     Pragma: no-cache
			 *
			 * 세션을 쓰는 모든 응답이 자동으로 캐시 불가가 된다. 그래서
			 * 브리지가 붙이던 no-store 는 사실 **중복이었다** — 그것만 지워서는
			 * 301 캐시 사고가 재현되지 않는다.
			 *
			 * 실험을 성립시키려면 세션이 붙인 것까지 걷어내야 한다.
			 * 이 분기는 그 목적으로만 존재한다 → docs/failure-scenarios.md C-1
			 */
			header_remove('Cache-Control');
			header_remove('Expires');
			header_remove('Pragma');
		}
		else
		{
			$this->output->set_header('Cache-Control: '.$cache);
		}
	}
}
