<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 콘텐츠 시드. CLI 전용.
 *
 *   php public/index.php cli/content seed
 *   php public/index.php cli/content clear
 *
 * 홈 화면이 보여 줄 작품·회차·연재요일·로케일을 채운다.
 *
 * **작품 제목은 지어낸 것이다.** 조사 대상 서비스의 작품명을 그대로
 * 쓰면 그건 베끼는 것이고, 이 저장소가 지켜 온 익명화 원칙에도 어긋난다
 * → mio/web/CLAUDE.md "지켜야 할 선"
 *
 * **구조는 조사에서 온 것이다.** 무엇을 베끼면 안 되고 무엇을 가져와야
 * 하는지가 갈리는 자리다 — 제목·이미지·문구는 그 회사 것이고,
 * *"작품은 연재요일을 여럿 갖는다"* 나 *"기다리면 무료는 작품 속성이다"* 는
 * **도메인의 사실**이다 → docs/research-method.md
 */
class Content extends MY_Controller
{
	/** 1=월 … 7=일. ISO 8601 과 같다 — 요일을 정수로 저장하는 이유는 data-model 6장. */
	const WORKS = array(
		// title, lang, age, status, wait_free_hours, days, episodes, synopsis
		array('심해 등대지기', 'ko', 'all', 'ongoing', 72, array(1, 4), 48,
			'수심 800미터 등대에 혼자 남은 관리인이 매일 같은 신호를 받는다.'),
		array('월요일의 세탁소', 'ko', '12', 'ongoing', 24, array(1,), 112,
			'맡긴 옷의 얼룩이 그 사람의 기억으로 나오는 세탁소.'),
		array('재보정', 'ko', '15', 'ongoing', NULL, array(2, 5), 31,
			'측정값이 맞는지 확인하려면 다른 자로 한 번 더 재야 한다.'),
		array('북위 66도', 'ko', 'all', 'rest', 168, array(3,), 20,
			'백야가 끝나지 않는 마을에서 시계를 고치는 일을 하는 사람.'),
		array('두 번째 우체통', 'ko', '12', 'finished', NULL, array(6, 7), 156,
			'보내지 않은 편지만 모으는 우체통이 골목 끝에 있다.'),
		array('관측 오차', 'ko', '15', 'ongoing', 48, array(4, 7), 64,
			'같은 사건을 본 두 사람의 진술이 3분씩 어긋난다.'),
		array('The Keeper of Depth', 'en', 'all', 'ongoing', 72, array(1, 4), 12,
			'A lighthouse keeper 800 metres down receives the same signal every day.'),
		array('深海の灯台守', 'ja', 'all', 'ongoing', 72, array(1, 4), 9,
			'水深800メートルの灯台に残された管理人。'),
	);

	/**
	 * URL 슬러그 → 언어. slug · lang · script · region · bcp47 · is_active
	 *
	 * **한 칸에 몰아넣지 않는다.** ISO 639-1(언어)·15924(문자)·3166-1(지역)은
	 * 서로 다른 축이고, `bcp47` 은 그것을 합쳐 `hreflang` 에 쓰는 값이다.
	 * 간체와 번체가 둘 다 `zh` 인데 **문자로 갈린다** — 언어 코드 하나로는
	 * 구분할 수 없고, 그게 축을 나눈 이유다 → data-model
	 */
	const LOCALES = array(
		array('ko', 'ko', NULL, 'KR', 'ko-KR', 1),
		array('en', 'en', NULL, 'US', 'en-US', 1),
		array('ja', 'ja', NULL, 'JP', 'ja-JP', 1),
		array('zh-hans', 'zh', 'Hans', 'CN', 'zh-Hans-CN', 0),
	);

	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}

		if (ENVIRONMENT === 'production' && ! tp_env_bool('SEED_ALLOWED'))
		{
			fwrite(STDERR, "production 에서는 막혀 있습니다. .env 에 SEED_ALLOWED=true 를 넣으세요.\n");
			exit(1);
		}
	}

	public function seed()
	{
		$this->locales();

		$works = 0;
		$eps   = 0;

		foreach (self::WORKS as $w)
		{
			list($title, $lang, $age, $status, $waitFree, $days, $epCount, $synopsis) = $w;

			$this->db->query(
				'INSERT IGNORE INTO works
					(work_uid, title, lang, age_rating_code, status, wait_free_hours, synopsis)
				 VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?)',
				array(bin2hex(tp_uuid7()), $title, $lang, $age, $status, $waitFree, $synopsis)
			);

			if ((int) $this->db->affected_rows() === 0)
			{
				continue;   // 이미 있다
			}

			$workId = (int) $this->db->insert_id();
			$works++;

			foreach ($days as $d)
			{
				$this->db->query(
					'INSERT IGNORE INTO work_publish_days (work_id, day_of_week) VALUES (?, ?)',
					array($workId, $d)
				);
			}

			$eps += $this->episodes($workId, $epCount);
		}

		$this->line(sprintf('작품 %d · 회차 %d · 로케일 %d', $works, $eps, count(self::LOCALES)));
	}

	public function clear()
	{
		// episodes·publish_days 는 works 에 CASCADE 로 붙어 있다.
		$this->db->query('DELETE FROM works');
		$this->db->query('DELETE FROM locales');
		$this->line('콘텐츠를 비웠습니다.');
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 회차를 만든다.
	 *
	 * **최신 3회는 무료, 나머지는 유료**로 둔다. 관측된 패턴이고
	 * (`is_charged` 가 회차 속성인 이유), 홈 화면의 "무료" 뱃지가
	 * 그 값에서 나온다.
	 *
	 * `published_at` 은 UTC 다. 표시 직전에만 변환한다 → data-model
	 */
	private function episodes($workId, $count)
	{
		$count = max(1, (int) $count);
		$now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$made  = 0;

		for ($seq = 1; $seq <= $count; $seq++)
		{
			// 최신이 마지막. 주 1회 연재를 가정해 과거로 거슬러 올린다.
			$at = $now->sub(new DateInterval('P'.(($count - $seq) * 7).'D'));

			$this->db->query(
				'INSERT IGNORE INTO episodes (work_id, seq, subtitle, is_charged, published_at)
				 VALUES (?, ?, ?, ?, ?)',
				array(
					$workId, $seq, $seq.'화',
					$seq <= $count - 3 ? 1 : 0,
					$at->format('Y-m-d H:i:s.v'),
				)
			);

			$made += (int) $this->db->affected_rows();
		}

		return $made;
	}

	private function locales()
	{
		foreach (self::LOCALES as $l)
		{
			$this->db->query(
				'INSERT IGNORE INTO locales (slug, lang, script, region, bcp47, is_active)
				 VALUES (?, ?, ?, ?, ?, ?)',
				$l
			);
		}
	}

	private function line($msg)
	{
		fwrite(STDOUT, $msg.PHP_EOL);
	}
}
