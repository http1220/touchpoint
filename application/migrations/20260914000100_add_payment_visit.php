<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * `payments` 에 결제 시점의 방문을 남긴다.
 *
 * ── 왜 필요한가 ──
 *
 * 지금 결제 전환의 유입 귀속은 `users.signup_visit_id` 를 경유한다.
 * 즉 **가입할 때의 접점에 붙는다.** 그런데 광고 정산에서 묻는 것은
 * 보통 그게 아니다 —
 *
 *   가입은 A 광고로 했고, 석 달 뒤 B 광고를 보고 돌아와 결제했다.
 *   이 매출은 누구 것인가?
 *
 * 지금 구조는 **무조건 A** 라고 답한다. 그게 first-touch 라면 의도한
 * 답이지만, 우리는 그렇게 정한 적이 없다 — 그냥 `payments` 에 방문을
 * 적을 자리가 없었을 뿐이다. **설계가 아니라 누락이었다.**
 *
 * 이 컬럼이 생기면 두 값이 다 남고, 어느 쪽으로 귀속할지는 그때
 * 고르는 문제가 된다 → docs/failure-scenarios.md C-2 가 남긴 질문
 *
 * ── FK 를 걸지 않는다 ──
 *
 * `visits` 는 3개월 뒤 파기된다(통신비밀보호법). `payments` 는 5년이다.
 * FK 를 걸면 파기가 막히거나(RESTRICT) 결제가 지워진다(CASCADE).
 * `users.signup_visit_id` 가 같은 이유로 FK 없이 있고, 그 판단은
 * E-2 에서 실측으로 확인했다 → docs/failure-scenarios.md E-2
 *
 * NULL 을 허용한다. 쿠키가 없는 결제(서버 간 호출·복구 작업)가 있다.
 */
class Migration_Add_payment_visit extends CI_Migration
{
	public function up()
	{
		$this->db->query(
			'ALTER TABLE payments
			 ADD COLUMN visit_id BIGINT UNSIGNED NULL
			 COMMENT "결제 시점의 방문. FK 없음 — visits 는 3개월 뒤 파기된다"
			 AFTER user_id'
		);

		/*
		 * 인덱스를 걸지 않는다.
		 *
		 * 이 컬럼으로 조회하는 쿼리가 아직 없다. 인덱스는 쓰는 쿼리가
		 * 생길 때 그 쿼리를 보고 만든다 — 미리 걸면 **어느 순서가 맞는지
		 * 모르는 채로** 만들게 되고, 1-3 에서 본 것처럼 기존 쿼리의
		 * 계획까지 흔든다 → docs/benchmarks.md 1-3
		 */
	}

	public function down()
	{
		$this->db->query('ALTER TABLE payments DROP COLUMN visit_id');
	}
}
