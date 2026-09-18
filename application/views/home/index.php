<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Support\PublishDay;

/**
 * 홈. 서버 렌더 · JS 없음 (ADR-009).
 *
 * 시트 두 장이다 — 넓은 가로 화면에서는 4:3 판, 세로 폰에서는 칸이 한 줄.
 *   1  서비스 머리 · 오늘(+다음 업데이트) · 최근 올라온 회차 · 회차가 많은 작품
 *   2  연재 요일 7줄
 *
 * **이 화면은 서비스다.** 시연 설명(한 문장 · ①②③ · 스키마 해설)은 /tour 로 옮겼다 — 2026-09-18.
 *
 * 칸의 순서는 DOM 순서 그대로다. 배치는 site.css 의 span 만 바꾼다.
 *
 * @var array  $byDay 1=월 … 7=일 — 7칸이 전부 온다(비어 있어도)
 * @var array  $top
 * @var array  $recent
 * @var array  $locales 활성 로케일 — 기본 로케일이 맨 앞
 * @var array  $locale  지금 그리는 로케일 (slug · lang · bcp47)
 * @var string $default_locale
 * @var int    $today KST 요일 (PublishDay)
 */
$AGE = array('all' => '전체', '12' => '12+', '15' => '15+', '19' => '19+');

$STATUS = array('ongoing' => '연재중', 'finished' => '완결', 'rest' => '휴재');

$repo = 'https://github.com/http1220/touchpoint/blob/main/';

/** 로케일의 정본 URL — 기본 로케일은 파라미터 없는 / 다 (LocaleChoice 와 같은 규칙) */
$locale_url = function ($slug) use ($default_locale)
{
	return tp_host_url('root', $slug === $default_locale ? '/' : '/?lang='.$slug);
};

$is_default = $locale['slug'] === $default_locale;

// 화면 문구는 한국어(<html lang="ko">)이고 작품 제목만 로케일을 따른다. 제목에만 lang 을 붙인다
$title_lang = $is_default ? '' : ' lang="'.html_escape($locale['bcp47']).'"';

/** 작품 카드 — 오늘 칸과 요일 줄이 같이 쓴다 */
$card = function (array $w, $size) use ($AGE, $STATUS, $title_lang)
{
	$id = (int) $w['id'];
	?>
	<li>
	  <a class="work work--<?= $size ?>" href="<?= html_escape(tp_host_url('lp', '/l/'.$id)) ?>">
	    <span class="work__cover cover-<?= $id % 6 ?>" aria-hidden="true">#<?= $id ?></span>
	    <span class="work__body">
	      <span class="work__title"<?= $title_lang ?>><?= html_escape($w['title']) ?></span>
	      <span class="work__meta">
	        <span class="badge"><?= html_escape($AGE[$w['age_rating_code']] ?? $w['age_rating_code']) ?></span>
	        <?php if ($w['wait_free_hours'] !== NULL): ?>
	          <span class="badge"><?= (int) $w['wait_free_hours'] ?>시간 후 무료</span>
	        <?php endif; ?>
	        <?php if ($w['status'] !== 'ongoing'): ?>
	          <span class="badge"><?= html_escape($STATUS[$w['status']] ?? $w['status']) ?></span>
	        <?php endif; ?>
	        <span class="work__eps"><?= (int) $w['ep_count'] ?>화</span>
	      </span>
	    </span>
	  </a>
	</li>
	<?php
};
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => 'touchpoint — 웹툰')); ?>
<?php foreach ($locales as $l): ?>
<link rel="alternate" hreflang="<?= html_escape($l['bcp47']) ?>" href="<?= html_escape($locale_url($l['slug'])) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= html_escape($locale_url($default_locale)) ?>">
<?php /* 정본을 밝힌다. 302 가 못 잡는 주소가 있다 — CI3 Input 이 $_GET 의 제어 문자를 컨트롤러보다 먼저
         지워서(remove_invisible_characters) /?lang=ja%00 은 LocaleChoice 에 "ja" 로 도착해 그대로 그려진다 */ ?>
<link rel="canonical" href="<?= html_escape($locale_url($locale['slug'])) ?>">
</head>
<body>
<main class="stage">

  <!-- ── 시트 1 · 서비스 ───────────────────────── -->
  <div class="sheet layout-home-1">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => 1)); ?>

    <header class="masthead">
      <p class="masthead__mark">touchpoint <span class="masthead__sub">웹툰</span></p>
      <nav aria-label="작품 언어">
        <ul class="lang">
          <?php foreach ($locales as $l): ?>
            <li><a href="<?= html_escape($locale_url($l['slug'])) ?>" hreflang="<?= html_escape($l['bcp47']) ?>"<?= $l['slug'] === $locale['slug'] ? ' aria-current="true"' : '' ?>><?= html_escape($l['slug']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </nav>
    </header>

    <section class="panel today" aria-labelledby="today-title">
      <?php if ( ! $is_default): ?>
        <p class="caption caption--corner">
          언어 <code><?= html_escape($locale['slug']) ?></code> — 바뀌는 것은 <strong>작품 데이터</strong>뿐입니다. 화면 문구는 번역하지 않았습니다.
        </p>
      <?php endif; ?>
      <h2 id="today-title" class="today__title">오늘 <span class="badge badge--strong"><?= html_escape(PublishDay::LABELS[$today]) ?>요일</span></h2>
      <?php if ($byDay[$today] === array()): ?>
        <?php
          // 비어 있다는 사실만 말하면 고장 난 것처럼 보인다. 이 언어의 작품이 있는 요일을 함께 말한다
          $days_with = array();
          foreach (PublishDay::LABELS as $n => $label)
          {
              if ($byDay[$n] !== array()) { $days_with[] = $label; }
          }
        ?>
        <p class="empty">
          오늘 올라오는 작품이 없습니다.
          <?php if ($days_with !== array()): ?>
            이 언어의 작품은 <strong><?= html_escape(implode('·', $days_with)) ?></strong>에 연재됩니다 — 아래 연재 요일.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <ul class="works">
          <?php foreach ($byDay[$today] as $w) { $card($w, 'large'); } ?>
        </ul>
      <?php endif; ?>

      <?php
        // 다음 업데이트 — 오늘 다음 날부터 돌며 작품이 있는 날 셋. 데이터는 이미 $byDay 에 다 있다
        $upcoming = array();
        for ($i = 1; $i <= 6 && count($upcoming) < 3; $i++)
        {
            $d = (($today - 1 + $i) % 7) + 1;
            if ($byDay[$d] !== array()) { $upcoming[$d] = $byDay[$d]; }
        }
      ?>
      <?php if ($upcoming !== array()): ?>
        <div class="next-up">
          <h3 class="next-up__title">다음 업데이트</h3>
          <ul class="next-up__list">
            <?php foreach ($upcoming as $d => $ws): ?>
              <li>
                <span class="badge"><?= html_escape(PublishDay::LABELS[$d]) ?></span>
                <span class="next-up__works"<?= $title_lang ?>><?= html_escape(implode(' · ', array_column($ws, 'title'))) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel recent" aria-labelledby="recent-title">
      <h2 id="recent-title">최근 올라온 회차</h2>
      <table class="data">
        <thead><tr><th scope="col">작품</th><th scope="col" class="n">회차</th><th scope="col">공개</th><th scope="col">구분</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $e): ?>
          <tr>
            <td><a href="<?= html_escape(tp_host_url('lp', '/l/'.(int) $e['work_id'])) ?>"<?= $title_lang ?>><?= html_escape($e['title']) ?></a></td>
            <td class="n"><?= (int) $e['seq'] ?>화</td>
            <td class="muted"><?= html_escape(substr((string) $e['published_at'], 0, 10)) ?></td>
            <td><span class="badge<?= $e['is_charged'] ? '' : ' badge--success' ?>"><?= $e['is_charged'] ? '유료' : '무료' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <?php /* 순위는 최근 회차 뒤에 온다 — 넓은 화면에서 아래 띠로 깔리는 자리와 같은 순서 */ ?>
    <section class="panel rank" aria-labelledby="top-title">
      <h2 id="top-title">회차가 많은 작품</h2>
      <ol class="rank-list">
      <?php foreach ($top as $w): ?>
        <li>
          <a href="<?= html_escape(tp_host_url('lp', '/l/'.(int) $w['id'])) ?>"<?= $title_lang ?>><?= html_escape($w['title']) ?></a>
          <span class="rank__meta"><?= (int) $w['ep_count'] ?>화 · <?= html_escape($STATUS[$w['status']] ?? $w['status']) ?></span>
        </li>
      <?php endforeach; ?>
      </ol>
    </section>
  </div>

  <!-- ── 시트 2 · 연재 요일 ─────────────────────── -->
  <div class="sheet layout-home-week">
    <section class="panel panel--bottom week-intro" aria-labelledby="week-title">
      <p class="eyebrow">요일은 KST 기준</p>
      <h2 id="week-title">연재 요일</h2>
      <p class="meta-line">작품을 누르면 그 작품의 랜딩으로 갑니다.</p>
    </section>

    <?php foreach (PublishDay::LABELS as $n => $label): ?>
      <section class="panel day<?= $n === $today ? ' day--today' : '' ?>" aria-labelledby="day-<?= $n ?>">
        <h3 class="day__name" id="day-<?= $n ?>"><?= html_escape($label) ?><?php if ($n === $today): ?> <span class="badge badge--strong">오늘</span><?php endif; ?></h3>
        <?php if ($byDay[$n] === array()): ?>
          <p class="empty">연재 없음</p>
        <?php else: ?>
          <ul class="works">
            <?php foreach ($byDay[$n] as $w) { $card($w, 'small'); } ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
