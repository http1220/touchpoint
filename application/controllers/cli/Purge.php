<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 보존기간 파기 배치. CLI 전용.
 *
 *   php public/index.php cli/purge plan             지울 건수만 센다 (기본)
 *   php public/index.php cli/purge run              실제로 지운다
 *   php public/index.php cli/purge run 90 5000      보존일수 · 회당 삭제 한도
 *
 * 설계가 약속해 놓고 구현이 없던 자리다. `docs/data-model.md` 7장에
 * 보존기간 표와 `DELETE` 두 줄이 적혀 있었지만, 그걸 도는 것이 없었다.
 * **파기되지 않는 보존정책은 정책이 아니라 문장이다.**
 *
 * 보존기간이 왜 다른가 → 근거가 다른 법이다.
 *
 *   visits · touchpoints · dispatch_log   3개월   통신비밀보호법(방문기록)
 *   users · conversions · payments …      5년     전자상거래법 · 전자금융거래법
 *
 * 20배 차이 나는 것을 같은 테이블에 두면 파기가 불가능해진다.
 * 여기서 정규화의 이유는 성능이 아니라 법이다 → docs/failure-scenarios.md E-2
 */
class Purge extends MY_Controller
{
	/** 방문기록 보존일수. 3개월. */
	const DEFAULT_DAYS = 90;

	/**
	 * 한 번에 지우는 최대 행수.
	 *
	 * 통째로 지우면 큰 트랜잭션이 되어 복제 지연을 만든다 — 복제본이
	 * 그 삭제를 단일 스레드로 다시 적용해야 하기 때문이다(→ benchmarks 6장).
	 * 파기 배치가 읽기 지연을 만드는 것은 본말전도다.
	 */
	const DEFAULT_CHUNK = 5000;

	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}
	}

	/** 무엇이 지워질지만 센다. 지우지 않는다. */
	public function plan($days = self::DEFAULT_DAYS)
	{
		$cutoff = self::cutoff($days);

		$this->line('기준 시각 '.$cutoff.' (보존 '.(int) $days.'일) — 세기만 합니다');

		foreach (self::targets() as $table => $col)
		{
			$n = $this->countOlderThan($table, $col, $cutoff);
			$this->line(sprintf('  %-18s %7d 건', $table, $n));
		}

		$this->line('실제로 지우려면: cli/purge run '.(int) $days);
	}

	/** 실제로 지운다. */
	public function run($days = self::DEFAULT_DAYS, $chunk = self::DEFAULT_CHUNK)
	{
		$cutoff = self::cutoff($days);
		$chunk  = max(1, min(50000, (int) $chunk));

		$this->line('기준 시각 '.$cutoff.' (보존 '.(int) $days.'일) · 회당 '.$chunk.'건');

		foreach (self::targets() as $table => $col)
		{
			$total = 0;
			$startedAt = microtime(TRUE);

			/*
			 * 한도까지 지우고 다시 부른다. 지울 게 없으면 0 이 나와 멈춘다.
			 *
			 * touchpoints 는 visits 에 ON DELETE CASCADE 라 따로 지우지 않아도
			 * 사라진다. 그래도 목록에 둔 이유는 **접점만 먼저 늙는 경우**가
			 * 있기 때문이다 — 방문은 3개월 안이고 접점은 그보다 오래된
			 * 상황은 생기지 않지만, 반대로 CASCADE 에만 기대면
			 * visits 가 남아 있는 한 접점도 영원히 남는다.
			 */
			do
			{
				$this->db->query(
					'DELETE FROM '.$table.' WHERE '.$col.' < ? LIMIT '.$chunk,
					array($cutoff)
				);
				$n = (int) $this->db->affected_rows();
				$total += $n;
			}
			while ($n === $chunk);

			$this->line(sprintf(
				'  %-18s %7d 건 삭제 · %dms',
				$table, $total, (int) round((microtime(TRUE) - $startedAt) * 1000)
			));
		}
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 지울 테이블과 기준 컬럼.
	 *
	 * 순서가 의미를 갖는다. 자식(touchpoints)을 먼저 지워야 부모를 지울 때
	 * CASCADE 가 할 일이 줄어든다 — 큰 CASCADE 한 번이 긴 트랜잭션이 된다.
	 *
	 * conversions · payments · users 는 여기 없다. 보존 5년이고,
	 * 그 파기는 회원 탈퇴·법정 보존기간 만료라는 다른 규칙을 따른다.
	 */
	private static function targets()
	{
		return array(
			'touchpoints'    => 'occurred_at',
			'collect_events' => 'received_at',
			'dispatch_log'   => 'created_at',
			'visits'         => 'first_seen_at',
		);
	}

	private static function cutoff($days)
	{
		$days = max(1, (int) $days);

		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->sub(new DateInterval('P'.$days.'D'))
			->format('Y-m-d H:i:s.v');
	}

	private function countOlderThan($table, $col, $cutoff)
	{
		return (int) $this->db
			->query('SELECT COUNT(*) AS n FROM '.$table.' WHERE '.$col.' < ?', array($cutoff))
			->row()
			->n;
	}

	private function line($msg)
	{
		fwrite(STDOUT, $msg.PHP_EOL);
	}
}
