<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 검증용 데이터 생성기. CLI 전용.
 *
 *   php public/index.php cli/seed conversion       전환 1건
 *   php public/index.php cli/seed conversion 500   500건 (인덱스 실측용)
 *
 * 운영 데이터를 만드는 도구가 아니다. 아웃박스 파이프라인과 인덱스를
 * 재려면 행이 있어야 하는데, 그걸 손으로 만들면 매번 다르게 만들어져
 * 측정이 비교되지 않는다.
 *
 * `EXPLAIN` 이 `ix_poll` 을 쓰는지 보려면 수만 건이 필요하다. 행이 몇 개
 * 뿐이면 옵티마이저가 풀스캔이 더 싸다고 판단하는데, **그건 인덱스가
 * 안 먹는 게 아니라 옵티마이저가 맞는 판단을 한 것**이다.
 */
class Seed extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}

		if (ENVIRONMENT === 'production' && ! tp_env_bool('SEED_ALLOWED'))
		{
			/*
			 * 운영에서는 기본적으로 막는다. 이 프로젝트의 서버는 운영이면서
			 * 실습 환경이라 열어 둘 필요가 있는데, 그럴 때도 .env 에
			 * SEED_ALLOWED=true 를 명시적으로 켜게 한다 — 실수로 도는 것과
			 * 의도하고 켜는 것은 다르다.
			 */
			fwrite(STDERR, "production 에서는 막혀 있습니다. .env 에 SEED_ALLOWED=true 를 넣으세요.\n");
			exit(1);
		}

		$this->load->model('conversion_model');
		$this->load->library('channels');
	}

	/**
	 * @param int    $count    만들 전환 수
	 * @param string $currency ISO 4217. `mix` 면 아래 목록에서 돌아가며 쓴다
	 *
	 * 통화를 섞을 수 있게 한 이유는 **소수 자릿수가 통화마다 다르기** 때문이다.
	 * 9900 이라는 minor unit 정수가 KRW 면 ₩9,900 이고 USD 면 $99.00 이다.
	 * 매체 대시보드에 찍히는 매출로 그 변환을 확인할 수 있다 → src/Channel/Ga4Channel::majorUnits
	 */
	public function conversion($count = 1, $currency = 'KRW')
	{
		$count = max(1, min(100000, (int) $count));
		$names = $this->channels->names();

		// 0자리 · 2자리 · 3자리를 하나씩. 자릿수별로 한 번씩은 지나가게.
		$mix = array('KRW', 'USD', 'JPY', 'EUR', 'BHD');
		$currency = strtoupper(trim((string) $currency));

		if ($names === array())
		{
			fwrite(STDERR, ".env 의 CHANNELS 가 비어 있습니다.\n");
			exit(1);
		}

		$made = 0;
		$enqueued = 0;
		$startedAt = microtime(TRUE);

		for ($i = 0; $i < $count; $i++)
		{
			$cur = ($currency === 'MIX') ? $mix[$i % count($mix)] : $currency;

			$r = $this->conversion_model->createWithOutbox(array(
				'type'        => 'purchase',
				'value_minor' => 9900,
				'currency'    => $cur,

				// dedup_key 는 유일해야 한다. 같은 값이면 UNIQUE 에 걸려
				// 두 번째부터 duplicated 로 돌아온다 — 그 동작도 확인 대상이다.
				'dedup_key'   => 'seed:'.bin2hex(random_bytes(12)),

				// 실제 전환이라면 _ga 쿠키에서 온다. 여기서는 흉내만 낸다.
				'client_id'   => mt_rand(100000000, 999999999).'.'.time(),
			), $names);

			if ($r['duplicated'] === FALSE)
			{
				$made++;
				$enqueued += $r['enqueued'];
			}
		}

		$this->line(sprintf(
			'전환 %d건 · 아웃박스 %d건 적재 (채널 %s · 통화 %s) · %dms',
			$made, $enqueued, implode(',', $names),
			$currency === 'MIX' ? implode('/', $mix) : $currency,
			(int) round((microtime(TRUE) - $startedAt) * 1000)
		));
	}

	/**
	 * 같은 `dedup_key` 로 **한 번만** 넣는다. 키를 밖에서 준다.
	 *
	 *   for i in 1..8; do cli/seed race KEY & done
	 *
	 * 이걸 병렬로 띄우는 것이 D-3 의 실험이다. 순차 두 번(`duplicate`)은
	 * UNIQUE 가 막는 걸 보여 줄 뿐이고, **경쟁 상태**는 동시에 때려야 한다.
	 * `Conversion_model` 주석이 "미리 SELECT 해서 막으면 동시 요청 둘이
	 * 다 통과한다" 고 주장하는데, 그 주장을 검증하려면 이 경로가 필요하다.
	 */
	public function race($key = NULL)
	{
		if ( ! is_string($key) OR trim($key) === '')
		{
			fwrite(STDERR, "dedup_key 를 인자로 주세요.\n");
			exit(1);
		}

		$r = $this->conversion_model->createWithOutbox(array(
			'type'        => 'purchase',
			'value_minor' => 9900,
			'currency'    => 'KRW',
			'dedup_key'   => $key,
			'client_id'   => mt_rand(100000000, 999999999).'.'.time(),
		), $this->channels->names());

		// 한 줄만 찍는다. 병렬 출력이 섞여도 세기 쉽게.
		$this->line($r['duplicated'] ? 'DUP' : 'NEW id='.$r['id']);
	}

	/** 같은 dedup_key 로 두 번 넣어 UNIQUE 가 막는지 본다. */
	public function duplicate()
	{
		$names = $this->channels->names();
		$key   = 'seed:dup:'.bin2hex(random_bytes(6));

		$payload = array(
			'type' => 'purchase', 'value_minor' => 1000, 'currency' => 'KRW',
			'dedup_key' => $key, 'client_id' => '111111111.'.time(),
		);

		$a = $this->conversion_model->createWithOutbox($payload, $names);
		$b = $this->conversion_model->createWithOutbox($payload, $names);

		$this->line('1회차: '.($a['duplicated'] ? '중복' : '생성 id='.$a['id']));
		$this->line('2회차: '.($b['duplicated'] ? '중복 — dedup_key UNIQUE 가 막았다' : '생성 id='.$b['id'].' ← 막혔어야 한다'));
	}

	private function line($msg)
	{
		fwrite(STDOUT, $msg.PHP_EOL);
	}
}
