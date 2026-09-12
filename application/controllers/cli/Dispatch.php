<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Dispatch\BackoffPolicy;
use App\Support\SystemClock;

/**
 * 매체 전송 워커. CLI 전용.
 *
 *   docker compose --profile worker up -d --scale worker=4
 *   docker compose exec app php public/index.php cli/dispatch once   # 1회만
 *
 * 아웃박스를 폴링해 채널 어댑터로 넘긴다. 동시 실행이 전제다 —
 * 워커를 넷으로 늘려도 같은 건이 두 번 전송되지 않는 것을
 * FOR UPDATE SKIP LOCKED 가 보장한다 → ADR-004
 *
 * 루프 순서가 이 파일의 전부다 → docs/outbox-and-channels.md 6장
 *
 *   ① 선점 + 상태 변경   한 트랜잭션 (Outbox_model::claim)
 *   ② 커밋               잠금을 여기서 푼다
 *   ③ 외부 HTTP          트랜잭션 밖
 *   ④ 결과 반영          sent / pending(재시도) / dead
 *   ⑤ 계측 기록          구간별 시간
 *
 * ③을 잠금 안에 두면 상대가 느린 만큼 잠금이 길어지고, 다른 워커가
 * 그동안 아무것도 못 한다.
 */
class Dispatch extends MY_Controller
{
	/** SIGTERM 을 받으면 현재 배치까지만 처리하고 내려간다. */
	private $running = TRUE;

	/** 좀비 회수를 매 루프 돌리지 않는다. 이 횟수마다 한 번. */
	const ZOMBIE_EVERY = 60;

	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}

		$this->load->model('outbox_model');
		$this->load->library('channels');
	}

	/** 계속 돈다. 컨테이너로 띄우는 기본 모드. */
	public function work()
	{
		$this->installSignalHandler();

		$interval = (int) (getenv('WORKER_POLL_INTERVAL_MS') ?: 1000);
		$batch    = (int) (getenv('WORKER_BATCH_SIZE') ?: 100);
		$names    = array_keys($this->channels->all());

		if ($names === array())
		{
			$this->line('채널이 하나도 없습니다. .env 의 CHANNELS 와 자격 증명을 확인하세요.');

			return;
		}

		$this->line('워커 시작 · 채널='.implode(',', $names).' batch='.$batch.' interval='.$interval.'ms');

		$loops = 0;

		while ($this->running)
		{
			if ($loops % self::ZOMBIE_EVERY === 0)
			{
				$reclaimed = $this->outbox_model->reclaimZombies(
					(int) (getenv('DISPATCH_TIMEOUT_MS') ?: 3000) / 1000 * 10 + 60
				);

				if ($reclaimed > 0)
				{
					$this->line('좀비 회수 '.$reclaimed.'건 — 전송 중 사라진 워커가 있었습니다');
				}
			}

			$done = $this->once($batch);
			$loops++;

			if ($done === 0)
			{
				// 할 일이 없을 때만 쉰다. 있으면 곧바로 다음 배치로 —
				// 밀린 큐를 폴링 간격만큼 느리게 비우지 않도록.
				usleep($interval * 1000);
			}
		}

		$this->line('워커 종료');
	}

	/**
	 * 한 배치만 처리한다. 검증과 디버깅용.
	 *
	 * @return int 처리한 건수
	 */
	public function once($limit = NULL)
	{
		$limit = (int) ($limit ?: (getenv('WORKER_BATCH_SIZE') ?: 100));

		$dbStart = microtime(TRUE);
		$rows    = $this->outbox_model->claim($limit);
		$claimMs = self::msSince($dbStart);

		if ($rows === array())
		{
			return 0;
		}

		$backoff = new BackoffPolicy();
		$clock   = new SystemClock();

		foreach ($rows as $row)
		{
			$this->handle($row, $backoff, $clock, $claimMs);
		}

		return count($rows);
	}

	/** 상태별 건수. 검증 스크립트가 쓴다. */
	public function status()
	{
		foreach ($this->outbox_model->counts() as $status => $n)
		{
			$this->line(sprintf('  %-8s %d', $status, $n));
		}
	}

	// ────────────────────────────────────────────────────────

	private function handle(array $row, BackoffPolicy $backoff, SystemClock $clock, $claimMs)
	{
		$total = microtime(TRUE);

		// ── 파싱 ──
		$t = microtime(TRUE);
		$payload = json_decode((string) $row['payload'], TRUE);
		$payload = is_array($payload) ? $payload : array();
		$parseMs = self::msSince($t);

		$channel = $this->channels->get($row['channel']);

		if ($channel === NULL)
		{
			/*
			 * 어댑터가 없다. .env 에서 매체를 빼거나 자격 증명이 사라진 경우다.
			 * 재시도해도 생기지 않으므로 dead 로 보낸다 — pending 으로 두면
			 * 워커가 영원히 같은 행을 집었다 놓는다.
			 */
			$this->outbox_model->applyResult(
				$row['id'],
				App\Channel\DispatchResult::dead(NULL, 0, '어댑터 없음: '.$row['channel'])
			);
			$this->line(sprintf('#%d %s → dead (어댑터 없음)', $row['id'], $row['channel']));

			return;
		}

		// ── 전송 (트랜잭션 밖) ──
		$t = microtime(TRUE);
		$result = $channel->send($payload);
		$sendMs = self::msSince($t);

		// ── 결과 반영 ──
		$t = microtime(TRUE);
		$nextRetryAt = $result->shouldRetry()
			? $backoff->nextRetryAt((int) $row['attempt'], $clock->now())
			: NULL;

		$applied = $this->outbox_model->applyResult($row['id'], $result, $nextRetryAt);

		$this->outbox_model->log(array(
			'outbox_id'   => $row['id'],
			'trace_id'    => $this->trace_id,
			'channel'     => $row['channel'],
			'attempt'     => $row['attempt'],
			'http_status' => $result->httpStatus,
			'parse_ms'    => $parseMs,
			'db_ms'       => $claimMs + self::msSince($t),
			'send_ms'     => $sendMs,
			'total_ms'    => self::msSince($total),
		));

		$this->line(sprintf(
			'#%d %s attempt=%d → %s (http=%s, %dms)%s',
			$row['id'], $row['channel'], $row['attempt'], $applied,
			$result->httpStatus === NULL ? '-' : $result->httpStatus,
			$sendMs,
			$result->error === NULL ? '' : ' · '.$result->error
		));
	}

	/**
	 * SIGTERM 을 받으면 현재 배치를 끝내고 내려간다.
	 *
	 * docker stop 은 SIGTERM 후 10초 뒤 SIGKILL 이다. 처리하지 않으면
	 * 배포할 때마다 전송 중이던 행이 `sending` 으로 남고, 좀비 회수를
	 * 기다려야 다시 나간다.
	 */
	private function installSignalHandler()
	{
		if ( ! function_exists('pcntl_signal'))
		{
			$this->line('경고: pcntl 이 없어 graceful shutdown 이 동작하지 않습니다.');

			return;
		}

		pcntl_async_signals(TRUE);

		$stop = function ($signo) {
			$this->running = FALSE;
			$this->line('신호 '.$signo.' 수신 — 현재 배치까지만 처리합니다.');
		};

		pcntl_signal(SIGTERM, $stop);
		pcntl_signal(SIGINT, $stop);
	}

	private static function msSince($startedAt)
	{
		return (int) round((microtime(TRUE) - $startedAt) * 1000);
	}

	private function line($msg)
	{
		fwrite(STDOUT, '['.tp_now_utc().'] '.$msg.PHP_EOL);
	}
}
