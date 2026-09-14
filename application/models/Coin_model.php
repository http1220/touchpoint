<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Payment\CoinProduct;

/**
 * 코인 원장의 적립 쪽.
 *
 * 소진(coin_spends)은 여기 없다 — 순서 규칙은 src/Coin/LedgerService 에
 * 이미 있고, 이번 범위는 "결제가 잡혔을 때 lot 하나를 만드는 것" 까지다.
 *
 * **coin_lots 에는 UNIQUE 가 없다.** payment_id 는 KEY 일 뿐이라
 * 같은 결제로 여덟 번 부르면 여덟 행이 생긴다. 그것을 막는 것은 이
 * 모델이 아니라 **호출자의 CAS** 다 → Payment_model::applyEvent()
 *
 * 그래서 이 클래스는 스스로 방어하지 않는다. 방어하는 척하면(예: 여기서
 * SELECT 로 중복을 걸러내면) D-3 대조군이 무엇을 재는지 흐려진다 —
 * "코인이 여덟 배" 라는 관측 지점이 여기다 → 계획 7장
 */
class Coin_model extends CI_Model
{
	const TABLE = 'coin_lots';

	/**
	 * 결제로 받은 코인 한 묶음.
	 *
	 * `amount` 와 `remaining` 이 같은 값으로 시작한다. 잔액을 users 의
	 * 숫자 하나로 두지 않는 이유는 만료다 — 유료 5년 · 무료 1년이라
	 * 합치면 어느 코인이 언제 만료되는지 복원할 수 없다 → ADR-006
	 *
	 * **호출자의 트랜잭션 안에서 불린다.** 여기서 trans_begin 을 하면
	 * 중첩이 되고, 중첩된 롤백은 아무것도 되돌리지 않는다(CI3 의 동작,
	 * Payment_model 주석 참조). 트랜잭션 경계는 호출자가 쥔다.
	 *
	 * @param int         $userId
	 * @param int         $paymentId
	 * @param CoinProduct $product   코인 수와 만료 규칙이 여기서 온다
	 * @param string|null $grantedAt DATETIME(3) UTC. 호출자의 트랜잭션과 같은 시각을 쓴다
	 * @return int lot id
	 */
	public function grantPurchaseLot($userId, $paymentId, CoinProduct $product, $grantedAt = NULL)
	{
		$grantedAt = $grantedAt === NULL ? tp_now_utc() : (string) $grantedAt;

		$expiresAt = $product
			->expiresAt(new DateTimeImmutable($grantedAt, new DateTimeZone('UTC')))
			->format('Y-m-d H:i:s.v');

		$this->db->query(
			'INSERT INTO '.self::TABLE.' (
				user_id, kind, source, channel, amount, remaining, payment_id, granted_at, expires_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			array(
				(int) $userId,

				// 유료 코인이다. kind 가 만료 규칙이 갈리는 축이라
				// 여기를 틀리면 5년짜리가 1년 만에 사라진다.
				'paid',
				'purchase',

				// channel 은 ENUM("web","ios","android") 다. 결제 경로를
				// 아직 구분하지 않으므로 web 으로 고정한다 — 스키마의
				// DEFAULT 에 기대지 않고 명시한다. 컬럼 순서를 읽는 사람이
				// "이 값은 어디서 오나" 를 여기서 답할 수 있게.
				'web',

				$product->coins,
				$product->coins,
				(int) $paymentId,
				$grantedAt,
				$expiresAt,
			)
		);

		return (int) $this->db->insert_id();
	}

	/**
	 * 이 결제로 지급된 lot 수. 측정용이다.
	 *
	 * D-3 의 기대값은 **1** 이고, 대조군(PAYMENT_WEBHOOK_PRECHECK=true)에서는
	 * 2 이상이 나와야 한다. 그 숫자가 "매출은 한 번인데 코인이 여러 번" 을
	 * 눈에 보이게 만드는 자리다 → 계획 8장 ①′
	 */
	/**
	 * 환불된 결제의 코인을 회수한다. 남은 만큼만 — RefundPolicy ②.
	 *
	 * `FOR UPDATE` 로 잠근다. 같은 순간 회차 열람이 이 lot 에서 코인을 쓰면
	 * 읽은 remaining 과 실제가 어긋난다. 호출자(applyEvent)의 트랜잭션 안이다.
	 *
	 * lot 을 지우지 않는다. coin_spends 가 lot_id 를 FK 로 가리키고, 무엇을
	 * 얼마나 쓴 뒤 환불됐는지가 남아야 분쟁에 답할 수 있다.
	 *
	 * @return array{lots: int, revoked: int, spent: int}
	 */
	public function revokeByPayment($paymentId)
	{
		$lots = $this->db
			->query('SELECT id, amount, remaining FROM '.self::TABLE.' WHERE payment_id = ? FOR UPDATE', array((int) $paymentId))
			->result_array();

		$out = array('lots' => count($lots), 'revoked' => 0, 'spent' => 0);

		foreach ($lots as $lot)
		{
			$r = \App\Payment\RefundPolicy::revocation((int) $lot['amount'], (int) $lot['remaining']);

			if ($r['revoke'] > 0)
			{
				$this->db->query('UPDATE '.self::TABLE.' SET remaining = 0 WHERE id = ?', array((int) $lot['id']));
			}

			$out['revoked'] += $r['revoke'];
			$out['spent']   += $r['spent'];
		}

		return $out;
	}

	public function countByPayment($paymentId)
	{
		$row = $this->db
			->query('SELECT COUNT(*) AS n FROM '.self::TABLE.' WHERE payment_id = ?', array((int) $paymentId))
			->row();

		return $row ? (int) $row->n : 0;
	}
}
