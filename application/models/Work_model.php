<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 작품 조회. 홈 화면이 쓴다.
 *
 * 커넥션을 인자로 받는다 — 홈은 복제본에서 읽어도 되는 화면이라
 * 호출자가 `$this->read()` 를 넘긴다 (`Collect_model::recent()` 와 같은 방식).
 *
 * **여기서 쓰기를 하지 않는다.** 복제본으로 들어온 커넥션에 쓰면
 * `--read-only=ON` 에 막힌다.
 */
class Work_model extends CI_Model
{
	/**
	 * 요일별 연재 목록.
	 *
	 * 작품 하나가 요일을 **여럿** 가질 수 있다(`work_publish_days` 가
	 * 별도 테이블인 이유). 그래서 같은 작품이 월·목에 다 나온다 —
	 * 배열에 중복이 아니라 **사실**이다 → docs/data-model.md
	 *
	 * @return array<int, list<array>> 1=월 … 7=일
	 */
	public function byPublishDay($db, $lang = 'ko')
	{
		$rows = $db->query(
			'SELECT d.day_of_week, w.id, w.title, w.status, w.age_rating_code,
			        w.wait_free_hours,
			        (SELECT COUNT(*) FROM episodes e WHERE e.work_id = w.id) AS ep_count,
			        (SELECT MAX(e.published_at) FROM episodes e WHERE e.work_id = w.id) AS last_at
			   FROM work_publish_days d
			   JOIN works w ON w.id = d.work_id
			  WHERE w.lang = ?
			  ORDER BY d.day_of_week, w.id',
			array($lang)
		)->result_array();

		$out = array_fill_keys(range(1, 7), array());

		foreach ($rows as $r)
		{
			$out[(int) $r['day_of_week']][] = $r;
		}

		return $out;
	}

	/**
	 * 회차가 많은 순.
	 *
	 * **조회수나 평점이 아니다.** 조사한 두 플랫폼 모두 조회수를 공개하지
	 * 않았고, 없는 지표를 화면에 지어내면 그 순간 이 화면은 흉내가 된다
	 * → docs/research-method.md "반박된 전제"
	 *
	 * 그래서 우리가 실제로 가진 값(회차 수)으로 줄을 세우고, 그 사실을
	 * 화면에도 적는다.
	 */
	public function topByEpisodes($db, $lang = 'ko', $limit = 5)
	{
		return $db->query(
			'SELECT w.id, w.title, w.status, w.age_rating_code, w.wait_free_hours,
			        COUNT(e.id) AS ep_count
			   FROM works w
			   LEFT JOIN episodes e ON e.work_id = w.id
			  WHERE w.lang = ?
			  GROUP BY w.id
			  ORDER BY ep_count DESC, w.id
			  LIMIT '.max(1, (int) $limit),
			array($lang)
		)->result_array();
	}

	/** 최근 올라온 회차. 무료/유료 구분이 회차 속성이라는 것을 보여 준다. */
	public function recentEpisodes($db, $lang = 'ko', $limit = 8)
	{
		return $db->query(
			'SELECT e.seq, e.subtitle, e.is_charged, e.published_at,
			        w.id AS work_id, w.title
			   FROM episodes e
			   JOIN works w ON w.id = e.work_id
			  WHERE w.lang = ?
			  ORDER BY e.published_at DESC
			  LIMIT '.max(1, (int) $limit),
			array($lang)
		)->result_array();
	}

	/** 활성 로케일. hreflang 에 쓴다. */
	public function locales($db)
	{
		return $db->query(
			'SELECT slug, lang, script, region, bcp47 FROM locales WHERE is_active = 1 ORDER BY slug'
		)->result_array();
	}
}
