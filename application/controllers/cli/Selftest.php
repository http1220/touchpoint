<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * DB 통합 테스트용 CLI. **운영에서는 돌지 않는다.**
 *
 *   tests/integration/run.sh         동시성 · 멱등성 · 대사
 *   tests/integration/migrations.sh  마이그레이션 왕복
 *
 * ── 왜 PHPUnit 이 아니라 CLI 인가 ──
 *
 * 막아야 할 것이 **여러 프로세스가 동시에** 같은 행을 만질 때의 결과다.
 * PHPUnit 한 프로세스 안에서는 동시성을 만들 수 없고, CI3 모델은 프레임워크
 * 부팅 없이 부를 수 없다. 그래서 모델을 부르는 얇은 CLI 를 두고, 셸이
 * `xargs -P` 로 동시에 띄워 결과를 센다 — 운영 서버에서 손으로 재던
 * D-1·D-3 을 CI 가 매번 재게 한 것이다 → docs/incidents/testable-cases.md
 *
 * 출력은 한 줄이다(숫자 또는 JSON). 셸이 비교하기 쉽게.
 */
class Selftest extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}

		// 운영 DB 에 시험 행을 쓰지 않는다. SEED_ALLOWED 로도 풀리지 않는다.
		if (ENVIRONMENT === 'production')
		{
			fwrite(STDERR, "selftest 는 production 에서 돌지 않습니다 (CI_ENVIRONMENT=testing).\n");
			exit(2);
		}

		$this->load->model('payment_model');
		$this->load->model('coin_model');
		$this->load->model('conversion_model');
	}

	/** 회원 하나. user_uid 를 출력한다. */
	public function user()
	{
		$uid = bin2hex(tp_uuid7());

		$this->db->query(
			'INSERT INTO users (user_uid, email, provider, lang, signup_visit_id, created_at) VALUES (UNHEX(?), ?, ?, ?, NULL, ?)',
			array($uid, 'selftest-'.$uid.'@example.invalid', 'local', 'ko', tp_now_utc())
		);

		$this->out($uid);
	}

	/** coin_100 결제 생성. 같은 key 면 duplicated. */
	public function payment($userUid, $key)
	{
		$r = $this->payment_model->createIfAbsent(array(
			'user_id'         => $this->payment_model->findUserIdByUid($userUid),
			'amount_minor'    => 9900,
			'currency'        => 'KRW',
			'idempotency_key' => (string) $key,
			'product'         => 'coin_100',
		));

		$this->out(json_encode(array('uid' => $r['uid_hex'], 'duplicated' => $r['duplicated'])));
	}

	/** 웹훅 한 건을 모델에 직접 적용한다(서명·HTTP 없이 — 그건 단위 테스트가 본다). */
	public function apply($paymentUid, $status)
	{
		$payment = $this->payment_model->findByUid($paymentUid);

		if ($payment === NULL)
		{
			fwrite(STDERR, "결제 없음: $paymentUid\n");
			exit(1);
		}

		$r = $this->payment_model->applyEvent($payment, $status, json_encode(array('selftest' => TRUE, 'status' => $status)));

		$this->out(json_encode(array(
			'applied' => $r['applied'],
			'status'  => $r['status'],
			'error'   => $r['error'],
		)));
	}

	/** 코인 회수를 직접 부른다. 멱등성 확인용. */
	public function revoke($paymentUid)
	{
		$payment = $this->payment_model->findByUid($paymentUid);

		$this->db->trans_begin();
		$r = $this->coin_model->revokeByPayment((int) $payment['id']);
		$this->db->trans_commit();

		$this->out(json_encode($r));
	}

	/** noop 채널로 전환 N건을 적재한다. */
	public function enqueue($n = 100)
	{
		for ($i = 0; $i < (int) $n; $i++)
		{
			$this->conversion_model->createWithOutbox(array(
				'type'      => 'signup',
				'dedup_key' => 'selftest:'.bin2hex(random_bytes(8)),
				'client_id' => '1.2',
			), array('noop'));
		}

		$this->out((string) (int) $n);
	}

	/**
	 * 세기. 셸이 기대값과 비교한다.
	 *
	 *   payments_by_key <key>        같은 멱등 키의 결제 행
	 *   captured_transitions <uid>   captured 로의 실제 전이(무시행 제외)
	 *   refunded_transitions <uid>
	 *   lots <uid>                   코인 lot 수
	 *   conversions <uid> <kind>     purchase | refund 전환 수
	 *   outbox <status>              noop 채널 아웃박스 상태별
	 *   dispatch_duplicates          같은 (outbox_id, attempt) 가 두 번 이상 기록된 수
	 *   tables                       ci_migrations 를 뺀 테이블 수
	 */
	public function count($what, $a = NULL, $b = NULL)
	{
		switch ($what)
		{
			case 'payments_by_key':
				$sql = 'SELECT COUNT(*) n FROM payments WHERE idempotency_key = ?';
				$bind = array($a);
				break;
			case 'captured_transitions':
			case 'refunded_transitions':
				$sql = 'SELECT COUNT(*) n FROM payment_events e JOIN payments p ON p.id = e.payment_id
				         WHERE p.payment_uid = UNHEX(?) AND e.to_status = ? AND e.from_status <> e.to_status';
				$bind = array($a, $what === 'captured_transitions' ? 'captured' : 'refunded');
				break;
			case 'lots':
				$sql = 'SELECT COUNT(*) n FROM coin_lots l JOIN payments p ON p.id = l.payment_id WHERE p.payment_uid = UNHEX(?)';
				$bind = array($a);
				break;
			case 'conversions':
				$sql = 'SELECT COUNT(*) n FROM conversions WHERE dedup_key = ?';
				$bind = array($b.':'.$a);
				break;
			case 'outbox':
				$sql = 'SELECT COUNT(*) n FROM dispatch_outbox WHERE channel = "noop" AND status = ?';
				$bind = array($a);
				break;
			case 'dispatch_duplicates':
				$sql = 'SELECT COUNT(*) n FROM (SELECT outbox_id, attempt FROM dispatch_log GROUP BY outbox_id, attempt HAVING COUNT(*) > 1) d';
				$bind = array();
				break;
			case 'tables':
				$sql = 'SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> "ci_migrations"';
				$bind = array();
				break;
			default:
				fwrite(STDERR, "모르는 항목: $what\n");
				exit(1);
		}

		$this->out((string) (int) $this->db->query($sql, $bind)->row()->n);
	}

	/**
	 * 스키마를 정렬된 텍스트로. 마이그레이션 왕복 전후를 diff 한다.
	 *
	 * 컬럼(타입·NULL·기본값·extra·코멘트) · 인덱스 · 외래키를 본다.
	 * 자동 증가 값처럼 데이터에 따라 달라지는 것은 넣지 않는다.
	 */
	public function schema()
	{
		$lines = array();

		foreach ($this->db->query(
			'SELECT table_name t, column_name c, column_type ty, is_nullable nu, COALESCE(column_default, "∅") d, extra x, column_comment cm
			   FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name <> "ci_migrations"'
		)->result_array() as $r)
		{
			$lines[] = "col  {$r['t']}.{$r['c']} {$r['ty']} null={$r['nu']} default={$r['d']} {$r['x']} # {$r['cm']}";
		}

		foreach ($this->db->query(
			'SELECT table_name t, index_name i, non_unique u, seq_in_index s, column_name c
			   FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name <> "ci_migrations"'
		)->result_array() as $r)
		{
			$lines[] = "idx  {$r['t']}.{$r['i']} unique=".($r['u'] ? 'no' : 'yes')." {$r['s']}:{$r['c']}";
		}

		foreach ($this->db->query(
			'SELECT k.table_name t, k.constraint_name n, k.column_name c, k.referenced_table_name rt, k.referenced_column_name rc, r.delete_rule dr
			   FROM information_schema.key_column_usage k
			   JOIN information_schema.referential_constraints r
			     ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
			  WHERE k.table_schema = DATABASE()'
		)->result_array() as $r)
		{
			$lines[] = "fk   {$r['t']}.{$r['n']} {$r['c']} -> {$r['rt']}.{$r['rc']} on delete {$r['dr']}";
		}

		sort($lines);

		$this->out(implode("\n", $lines));
	}

	private function out($s)
	{
		fwrite(STDOUT, $s.PHP_EOL);
	}
}
