<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Attribution\Touchpoint;
use App\Attribution\Resolution;

/**
 * 방문과 유입 접점.
 *
 * 판단은 여기 없다. "이번 유입으로 first/last 를 어떻게 바꿀 것인가" 는
 * src/Attribution/TouchpointResolver.php 가 정하고, 이 모델은 저장만 한다.
 * 그래야 규칙을 프레임워크 없이 테스트할 수 있다 → ADR-017
 *
 * 쓰기는 전부 $this->db (write 그룹, 프라이머리) 로 간다.
 * 방문을 만들자마자 그 id 로 접점을 넣어야 하므로 복제본을 거칠 수 없다.
 */
class Visit_model extends CI_Model
{
	const TABLE = 'visits';

	/**
	 * 쿠키의 방문 식별자로 방문을 찾는다.
	 *
	 * @param string $uidHex 32자 hex
	 * @return int|null 방문 id
	 */
	public function findByUid($uidHex)
	{
		if ( ! self::isUidHex($uidHex))
		{
			return NULL;
		}

		$row = $this->db
			->select('id')
			->where('visit_uid', hex2bin($uidHex), FALSE)
			->get(self::TABLE, 1)
			->row();

		return $row ? (int) $row->id : NULL;
	}

	/**
	 * 방문을 새로 만든다.
	 *
	 * ip 와 ua 는 원본을 넣지 않는다. 어트리뷰션에 원본이 필요 없고,
	 * 보존기간 3개월 동안 원본 IP 를 들고 있을 이유도 없다.
	 * → docs/data-model.md 2장
	 *
	 * @param array $ctx landing_path · referrer · ua · ip · lang · country
	 * @return array [id, uid_hex]
	 */
	public function create(array $ctx)
	{
		$uidBin = tp_uuid7();

		$this->db->insert(self::TABLE, array(
			'visit_uid'     => $uidBin,
			'first_seen_at' => tp_now_utc(),
			'landing_path'  => mb_substr((string) $ctx['landing_path'], 0, 512),
			'referrer'      => isset($ctx['referrer']) && $ctx['referrer'] !== ''
				? mb_substr((string) $ctx['referrer'], 0, 512) : NULL,
			'ua_hash'       => tp_hash($ctx['ua'] ?? NULL),
			'ip_hash'       => tp_hash($ctx['ip'] ?? NULL),
			'country'       => $ctx['country'] ?? NULL,
			'lang'          => $ctx['lang'] ?? NULL,
		));

		return array(
			'id'      => (int) $this->db->insert_id(),
			'uid_hex' => bin2hex($uidBin),
		);
	}

	/**
	 * 이 방문의 first / last 접점을 도메인 객체로 돌려준다.
	 *
	 * @return array [first => ?Touchpoint, last => ?Touchpoint]
	 */
	public function touchpoints($visitId)
	{
		$rows = $this->db
			->where('visit_id', (int) $visitId)
			->get('touchpoints')
			->result_array();

		$out = array('first' => NULL, 'last' => NULL);

		foreach ($rows as $row)
		{
			$out[$row['position']] = self::hydrate($row);
		}

		return $out;
	}

	/**
	 * 판정 결과를 저장한다.
	 *
	 * first 는 INSERT IGNORE — 이미 있으면 그대로 둔다(최초 유입 보존).
	 * last 는 UPSERT — 올 때마다 갱신한다.
	 *
	 * 두 규칙 모두 UNIQUE (visit_id, position) 이 있어야 성립한다.
	 * 애플리케이션이 아니라 DB 가 보장하는 자리다 → docs/data-model.md 2장
	 */
	public function apply($visitId, Resolution $resolution)
	{
		if ( ! $resolution->changesAnything())
		{
			return;
		}

		if ($resolution->createFirst !== NULL)
		{
			$this->insertIgnore((int) $visitId, 'first', $resolution->createFirst);
		}

		if ($resolution->upsertLast !== NULL)
		{
			$this->upsertLast((int) $visitId, $resolution->upsertLast);
		}
	}

	// ────────────────────────────────────────────────────────

	private function insertIgnore($visitId, $position, Touchpoint $t)
	{
		$cols = self::columns();
		$sql  = 'INSERT IGNORE INTO touchpoints (visit_id, position, '.implode(', ', $cols).')
		         VALUES ('.implode(', ', array_fill(0, count($cols) + 2, '?')).')';

		$this->db->query($sql, array_merge(array($visitId, $position), self::values($t)));
	}

	private function upsertLast($visitId, Touchpoint $t)
	{
		$cols = self::columns();
		$set  = array();
		foreach ($cols as $c)
		{
			$set[] = $c.' = VALUES('.$c.')';
		}

		$sql = 'INSERT INTO touchpoints (visit_id, position, '.implode(', ', $cols).')
		        VALUES ('.implode(', ', array_fill(0, count($cols) + 2, '?')).')
		        ON DUPLICATE KEY UPDATE '.implode(', ', $set);

		$this->db->query($sql, array_merge(array($visitId, 'last'), self::values($t)));
	}

	/** 컬럼 순서와 values() 의 순서는 함께 움직여야 한다. */
	private static function columns()
	{
		return array(
			'pid', 'subpid', 'channel',
			'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
			'gclid', 'fbclid', 'occurred_at',
		);
	}

	private static function values(Touchpoint $t)
	{
		return array(
			$t->pid, $t->subpid, $t->channel,
			$t->utmSource, $t->utmMedium, $t->utmCampaign, $t->utmContent, $t->utmTerm,
			$t->gclid, $t->fbclid,
			$t->occurredAt->format('Y-m-d H:i:s.v'),
		);
	}

	private static function hydrate(array $row)
	{
		return new Touchpoint(
			$row['pid'], $row['subpid'], $row['channel'],
			$row['utm_source'], $row['utm_medium'], $row['utm_campaign'],
			$row['utm_content'], $row['utm_term'],
			$row['gclid'], $row['fbclid'],
			new DateTimeImmutable($row['occurred_at'], new DateTimeZone('UTC'))
		);
	}

	private static function isUidHex($raw)
	{
		return is_string($raw) && preg_match('/\A[0-9a-f]{32}\z/', $raw) === 1;
	}
}
