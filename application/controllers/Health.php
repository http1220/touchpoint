<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 헬스체크.
 *
 * live 와 ready 를 나눈다. 하나로 합치면 DB가 잠깐 흔들릴 때
 * 오케스트레이터가 멀쩡한 앱 프로세스를 죽여버린다.
 *
 *   /healthz  프로세스가 살아있는가            — DB 를 건드리지 않는다
 *   /readyz   요청을 받을 준비가 되었는가       — 쓰기·읽기 커넥션 둘 다 확인
 */
class Health extends MY_Controller
{
	public function live()
	{
		$this->json(200, array(
			'status'   => 'ok',
			'ts'       => tp_now_utc(),
			'trace_id' => $this->trace_id,
		));
	}

	public function ready()
	{
		$checks = array();
		$ok     = TRUE;

		// 쓰기 커넥션
		try
		{
			$this->db->query('SELECT 1');
			$checks['write'] = 'ok';
		}
		catch (Exception $e)
		{
			$checks['write'] = 'fail';
			$ok = FALSE;
		}

		// 배정받은 읽기 커넥션. 어느 쪽으로 배정됐는지도 같이 내려준다 —
		// 복제 지연을 조사할 때 "이 요청이 어디를 읽었는가"가 첫 질문이다.
		try
		{
			$this->read()->query('SELECT 1');
			$checks['read'] = 'ok';
		}
		catch (Exception $e)
		{
			$checks['read'] = 'fail';
			$ok = FALSE;
		}

		$checks['read_target'] = $this->readTarget();

		$this->json($ok ? 200 : 503, array(
			'status'   => $ok ? 'ok' : 'degraded',
			'checks'   => $checks,
			'ts'       => tp_now_utc(),
			'trace_id' => $this->trace_id,
		));
	}
}
