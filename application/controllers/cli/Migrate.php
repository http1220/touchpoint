<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 마이그레이션 실행기. CLI 전용.
 *
 *   docker compose exec app php public/index.php cli/migrate latest
 *   docker compose exec app php public/index.php cli/migrate to 20260909000300
 *   docker compose exec app php public/index.php cli/migrate current
 *
 * 웹에서 실행되지 않는다. 스키마를 바꾸는 경로가 브라우저 요청 하나로 열려 있으면
 * 언젠가 반드시 실수로 눌린다.
 */
class Migrate extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}

		$this->load->library('migration');
	}

	/** 최신까지 올린다. migration.php 의 migration_version 이 목표값이다. */
	public function latest()
	{
		$before = $this->version();

		if ($this->migration->latest() === FALSE)
		{
			$this->fail($this->migration->error_string());
		}

		$this->line('마이그레이션 완료: '.$before.' → '.$this->version());
	}

	/** 특정 버전으로. 내려가는 것도 된다 — down() 이 실제로 도는지 확인하는 용도. */
	public function to($version = NULL)
	{
		if ($version === NULL)
		{
			$this->fail('버전을 지정하세요. 예: cli/migrate to 20260909000300');
		}

		$before = $this->version();

		if ($this->migration->version($version) === FALSE)
		{
			$this->fail($this->migration->error_string());
		}

		$this->line('마이그레이션 완료: '.$before.' → '.$this->version());
	}

	/** 지금 DB가 어느 버전인지. */
	public function current()
	{
		$this->line('현재 버전: '.$this->version());
	}

	/**
	 * DB 가 기록하고 있는 현재 버전.
	 *
	 * migration_table 이름을 config/migration.php 에서 읽어야 하는데,
	 * Migration 라이브러리는 그 설정을 자기 안으로만 가져가고
	 * $this->config 에는 올리지 않는다. 그래서 여기서 한 번 더 로드한다.
	 * (하드코딩하면 설정을 바꿨을 때 조용히 어긋난다)
	 */
	private function version()
	{
		$this->config->load('migration', FALSE, TRUE);
		$table = $this->config->item('migration_table') ?: 'ci_migrations';

		if ( ! $this->db->table_exists($table))
		{
			return '(없음)';
		}

		$row = $this->db->get($table)->row();

		return $row ? $row->version : '(없음)';
	}

	private function line($msg)
	{
		fwrite(STDOUT, $msg.PHP_EOL);
	}

	/** 실패는 stderr 로 보내고 종료코드를 남긴다. CI 파이프라인이 이걸 본다. */
	private function fail($msg)
	{
		fwrite(STDERR, '실패: '.$msg.PHP_EOL);
		exit(1);
	}
}
