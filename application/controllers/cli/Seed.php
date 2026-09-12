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

	public function conversion($count = 1)
	{
		$count = max(1, min(100000, (int) $count));
		$names = $this->channels->names();

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
			$r = $this->conversion_model->createWithOutbox(array(
				'type'        => 'purchase',
				'value_minor' => 9900,
				'currency'    => 'KRW',

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
			'전환 %d건 · 아웃박스 %d건 적재 (채널 %s) · %dms',
			$made, $enqueued, implode(',', $names),
			(int) round((microtime(TRUE) - $startedAt) * 1000)
		));
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
