<?php
defined('BASEPATH') OR exit('No direct script access allowed');


/**
 * 매체 전송 워커. CLI 전용.
 *
 *   docker compose --profile worker up --scale worker=4
 *
 * 아웃박스를 폴링해 채널 어댑터로 넘긴다. 동시 실행이 전제다 —
 * 워커를 4개로 늘려도 같은 건이 두 번 전송되지 않는 것을
 * FOR UPDATE SKIP LOCKED 로 보장한다. → ADR-004
 *
 * 지금은 루프와 잠금 골격만 있다. 실제 전송은 채널 어댑터와 함께 붙인다.
 * → docs/roadmap.md Phase 1
 */
class Dispatch extends MY_Controller
{
	/** @var bool SIGTERM 을 받으면 내려간다. */
	private $running = TRUE;

	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}
	}

	public function work()
	{
		$this->installSignalHandler();

		$interval = (int) (getenv('WORKER_POLL_INTERVAL_MS') ?: 1000);
		$batch    = (int) (getenv('WORKER_BATCH_SIZE') ?: 100);

		$this->line('워커 시작 · batch='.$batch.' interval='.$interval.'ms');

		while ($this->running)
		{
			$claimed = $this->claim($batch);

			if ($claimed === 0)
			{
				// 할 일이 없을 때만 쉰다. 있으면 곧바로 다음 배치로 간다 —
				// 밀린 큐를 폴링 간격만큼 느리게 비우는 일이 없도록.
				usleep($interval * 1000);
			}
		}

		$this->line('워커 종료');
	}

	/**
	 * 배치 하나를 선점한다.
	 *
	 * 트랜잭션 안에서 SKIP LOCKED 로 잠그고, 그 자리에서 상태를 바꾼다.
	 * 잠금을 풀고 나서 상태를 바꾸면 그 틈에 다른 워커가 같은 행을 집는다.
	 *
	 * @return int 선점한 건수
	 */
	private function claim($limit)
	{
		$this->db->trans_begin();

		$rows = $this->db->query(
			'SELECT id, conversion_id, channel, payload, attempt
			   FROM dispatch_outbox
			  WHERE status = ? AND next_retry_at <= ?
			  ORDER BY id
			  LIMIT '.(int) $limit.'
			  FOR UPDATE SKIP LOCKED',
			array('pending', tp_now_utc())
		)->result_array();

		if ($rows === array())
		{
			$this->db->trans_rollback();

			return 0;
		}

		// TODO(Phase 1): 채널 어댑터 호출. 지금은 선점만 검증한다.
		//   - 전송은 트랜잭션 밖에서 한다. 외부 HTTP 를 트랜잭션 안에 넣으면
		//     상대 서버가 느릴 때 잠금이 그만큼 길어진다.
		//   - 결과에 따라 sent / next_retry_at 갱신 / dead 로 나눈다.

		$this->db->trans_rollback();

		$this->line('선점 '.count($rows).'건 (전송은 미구현)');

		return count($rows);
	}

	/**
	 * SIGTERM 을 받으면 현재 배치를 끝내고 내려간다.
	 *
	 * docker stop 은 SIGTERM 후 10초 뒤 SIGKILL 이다. 이걸 처리하지 않으면
	 * 배포할 때마다 전송 중이던 건이 pending 으로 남거나 중복 전송된다.
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

	private function line($msg)
	{
		fwrite(STDOUT, '['.tp_now_utc().'] '.$msg.PHP_EOL);
	}
}
