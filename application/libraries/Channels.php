<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\ChannelInterface;
use App\Channel\CurlHttpClient;
use App\Channel\Ga4Channel;
use App\Channel\NoopChannel;

/**
 * 채널 어댑터 조립. CI3 와 `src/Channel/` 사이의 다리.
 *
 * DI 컨테이너를 넣지 않는다. 어댑터가 서너 개이고, 컨테이너를 들이면
 * 설정 파일이 하나 더 늘 뿐 얻는 게 없다 → ADR-017 ②
 *
 * **신규 매체를 붙이는 일이 여기 한 줄 + `src/Channel/` 클래스 하나**로
 * 끝나야 한다는 것이 ADR-005 의 주장이다. 이 파일이 그 주장의 시험대다 —
 * `build()` 의 match 절이 길어지기 시작하면 주장이 무너지고 있는 것이다.
 */
class Channels
{
	/** @var array<string, ChannelInterface>|null 지연 생성 */
	private $adapters = NULL;

	/**
	 * `.env` 의 CHANNELS 에 적힌 순서대로.
	 *
	 * @return array<string, ChannelInterface>
	 */
	public function all()
	{
		if ($this->adapters !== NULL)
		{
			return $this->adapters;
		}

		$this->adapters = array();

		foreach ($this->names() as $name)
		{
			$adapter = $this->build($name);

			if ($adapter === NULL)
			{
				// 모르는 이름은 조용히 건너뛰지 않는다. .env 오타가
				// "전송이 안 되네" 로만 나타나면 원인을 찾기 어렵다.
				log_message('error', 'channels: 모르는 매체 이름 — '.$name);

				continue;
			}

			$this->adapters[$name] = $adapter;
		}

		return $this->adapters;
	}

	/**
	 * 아웃박스 적재용 이름 목록.
	 *
	 * 어댑터를 만들지 않고 이름만 본다 — 전환을 기록하는 트랜잭션 안에서
	 * 부르므로, 거기서 HTTP 클라이언트를 만들 이유가 없다.
	 *
	 * @return list<string>
	 */
	public function names()
	{
		$raw = (string) (getenv('CHANNELS') ?: '');
		$out = array();

		foreach (explode(',', $raw) as $name)
		{
			$name = strtolower(trim($name));

			if ($name !== '' && ! in_array($name, $out, TRUE))
			{
				$out[] = $name;
			}
		}

		return $out;
	}

	public function get($name)
	{
		$all = $this->all();

		return $all[$name] ?? NULL;
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 이름 하나를 어댑터로.
	 *
	 * 자격 증명이 없으면 어댑터를 만들지 않는다. 만들어 두면 매 전송이
	 * `dead` 로 떨어지고 아웃박스가 실패로 가득 찬다 — 설정이 빠졌다는
	 * 사실이 로그 한 줄로 끝나는 편이 낫다.
	 */
	private function build($name)
	{
		return match ($name) {
			'ga4'  => $this->ga4(),
			'noop' => $this->noop(),
			default => NULL,
		};
	}

	/**
	 * 아무 데도 보내지 않는 채널.
	 *
	 * `.env` 의 NOOP_OUTCOME 으로 **실패를 주입**할 수 있다. 실패 경로는
	 * 성공 경로보다 재현하기 어려운데, 매체가 우리 사정에 맞춰 죽어 주지
	 * 않기 때문이다. GA4 는 일부러 깨진 페이로드를 보내도 2xx 를 돌려준다.
	 *
	 *   sent    204        기본값
	 *   retry   503        5xx — 재시도 대상
	 *   dead    400        4xx — 우리 요청이 잘못됐으므로 재시도하지 않는다
	 *
	 * 운영에서 쓸 값이 아니다. 실패 시나리오 D-2 를 재려고 열어 둔 문이고,
	 * 이름 그대로 아무 데도 보내지 않으므로 매체에 영향이 없다.
	 * → docs/failure-scenarios.md D-2
	 */
	private function noop()
	{
		$outcome = strtolower(trim((string) (getenv('NOOP_OUTCOME') ?: 'sent')));

		if ( ! in_array($outcome, array('sent', 'retry', 'dead'), TRUE))
		{
			log_message('error', 'channels: NOOP_OUTCOME 값이 올바르지 않습니다 — '.$outcome);
			$outcome = 'sent';
		}

		return new NoopChannel('noop', $outcome);
	}

	private function ga4()
	{
		$id     = (string) (getenv('GA4_MEASUREMENT_ID') ?: '');
		$secret = (string) (getenv('GA4_API_SECRET') ?: '');

		if ($id === '' OR $secret === '')
		{
			log_message('error', 'channels: GA4 자격 증명이 비어 있어 어댑터를 만들지 않습니다.');

			return NULL;
		}

		return new Ga4Channel(
			new CurlHttpClient(),
			$id,
			$secret,
			tp_env_bool('GA4_DEBUG'),
			(int) (getenv('DISPATCH_TIMEOUT_MS') ?: 3000)
		);
	}
}
