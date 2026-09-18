<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * payment_events.source 에 'return' 을 더한다.
 *
 * 이니시스 카드 결제는 웹훅이 없다. 승인 결과는 브라우저 복귀(returnUrl) 뒤
 * **우리가 동기로 요청해** 받는다 → docs/plan-multi-pg.md B3. 웹훅도 API 도
 * 관리자 조작도 아닌 네 번째 문이라 따로 적는다.
 *
 * **이걸 빠뜨리면 모든 이니시스 결제가 망취소된다.** ENUM 밖 값은 strict 모드에서
 * 오류이고, applyEvent 가 롤백 → db-error → 호출자가 망취소한다. 코드를 다 쓰고
 * 스키마를 대조하다 찾았다(09-19) — 단위 테스트는 DB 를 안 타서 못 잡는다.
 *
 * 값은 **끝에 붙인다.** MySQL 8 은 ENUM 끝에 값을 더하는 것을 메타데이터 변경으로
 * 처리한다(테이블을 다시 쓰지 않는다). 중간에 끼우면 기존 행의 내부 번호가 밀린다.
 */
class Migration_Add_payment_event_source_return extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'ALTER TABLE payment_events
			   MODIFY source ENUM("api","webhook","admin","return") NOT NULL'
		);
	}

	public function down()
	{
		// return 행이 있으면 되돌릴 수 없다 — 지우지 않고 막는다.
		$n = (int) $this->db->query('SELECT COUNT(*) AS n FROM payment_events WHERE source = "return"')->row()->n;

		if ($n > 0)
		{
			show_error('payment_events 에 source=return 행이 '.$n.'개 있어 되돌릴 수 없습니다.');
		}

		$this->db->query(
			'ALTER TABLE payment_events
			   MODIFY source ENUM("api","webhook","admin") NOT NULL'
		);
	}
}
