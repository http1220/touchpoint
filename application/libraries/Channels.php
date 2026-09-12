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
			'noop' => new NoopChannel(),
			default => NULL,
		};
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
