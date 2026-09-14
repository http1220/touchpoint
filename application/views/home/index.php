<?php
/**
 * 홈. 서버 렌더 · JS 없음 (ADR-009).
 *
 * @var array $byDay 1=월 … 7=일
 * @var array $top
 * @var array $recent
 * @var array $locales
 * @var int   $today
 */
$DAYS = array(1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일');

$AGE = array('all' => '전체', '12' => '12+', '15' => '15+', '19' => '19+');

$STATUS = array('ongoing' => '연재중', 'finished' => '완결', 'rest' => '휴재');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>touchpoint — 웹툰</title>
<?php foreach ($locales as $l): ?>
<link rel="alternate" hreflang="<?= html_escape($l['bcp47']) ?>" href="/?lang=<?= html_escape($l['slug']) ?>">
<?php endforeach; ?>
<style>
:root{--bg:#0f1115;--fg:#e6e8ec;--dim:#8b93a1;--line:#232733;--card:#171b23;--accent:#ffd166}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);
     font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:inherit;text-decoration:none}
.wrap{max-width:62rem;margin:0 auto;padding:1.5rem 1.25rem 4rem}
header{display:flex;align-items:baseline;gap:.75rem;flex-wrap:wrap;
       padding-bottom:1rem;border-bottom:1px solid var(--line)}
header b{font-size:1.15rem}
header .tag{color:var(--dim);font-size:.85rem}
nav.lang{margin-left:auto;display:flex;gap:.5rem}
nav.lang a{color:var(--dim);font-size:.85rem;padding:.1rem .45rem;border:1px solid var(--line);border-radius:3px}
h2{font-size:1rem;margin:2.25rem 0 .75rem}
.days{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
.days span{padding:.3rem .7rem;border:1px solid var(--line);border-radius:999px;
           font-size:.85rem;color:var(--dim)}
.days span.on{background:var(--accent);color:#1a1a1a;border-color:var(--accent);font-weight:600}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr));gap:.85rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:6px;
      padding:.75rem;display:flex;flex-direction:column;gap:.35rem}
.card:hover{border-color:#3a4152}
.thumb{aspect-ratio:3/4;border-radius:4px;display:flex;align-items:center;justify-content:center;
       font-size:.75rem;color:#5b6270;background:linear-gradient(160deg,#1d2230,#141821)}
.t{font-weight:600;line-height:1.35}
.meta{color:var(--dim);font-size:.8rem;display:flex;gap:.35rem;flex-wrap:wrap;align-items:center}
.b{display:inline-block;padding:.02rem .35rem;border-radius:3px;font-size:.75rem;line-height:1.5}
.b.free{background:#1f3a2a;color:#7fd6a0}
.b.wait{background:#3a3520;color:#e0c77f}
.b.age{background:#2a2030;color:#c9a0d6}
.b.paid{background:#2a2530;color:#9aa0ab}
.b.rest{background:#2a2a2a;color:#a0a0a0}
table{width:100%;border-collapse:collapse;font-size:.9rem}
td,th{text-align:left;padding:.4rem .5rem .4rem 0;border-bottom:1px solid var(--line)}
th{color:var(--dim);font-weight:400}
ol{margin:0;padding-left:1.25rem}
ol li{margin:.3rem 0}
.note{margin-top:2.5rem;padding:1rem;background:#12161d;border-left:2px solid #3a4152;
      color:#a8b0bd;font-size:.88rem}
.note strong{color:var(--fg)}
.note code{background:#1a1e27;padding:.05rem .3rem;border-radius:3px}
footer{margin-top:2rem;color:#5b6270;font-size:.8rem}
</style>
</head>
<body>
<div class="wrap">

<header>
  <b>touchpoint</b>
  <span class="tag">웹툰 · 어트리뷰션 파이프라인 시연</span>
  <nav class="lang">
    <?php foreach ($locales as $l): ?>
      <a href="/?lang=<?= html_escape($l['slug']) ?>"><?= html_escape($l['slug']) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<h2>요일 연재</h2>
<div class="days">
  <?php foreach ($DAYS as $n => $label): ?>
    <span class="<?= $n === $today ? 'on' : '' ?>"><?= html_escape($label) ?><?= $n === $today ? ' 오늘' : '' ?></span>
  <?php endforeach; ?>
</div>

<?php if ($byDay[$today] === array()): ?>
  <p style="color:#5b6270">오늘 올라오는 작품이 없습니다.</p>
<?php else: ?>
  <div class="grid">
  <?php foreach ($byDay[$today] as $w): ?>
    <a class="card" href="/l/<?= (int) $w['id'] ?>">
      <div class="thumb">작품 <?= (int) $w['id'] ?></div>
      <div class="t"><?= html_escape($w['title']) ?></div>
      <div class="meta">
        <span class="b age"><?= html_escape($AGE[$w['age_rating_code']] ?? $w['age_rating_code']) ?></span>
        <?php if ($w['wait_free_hours'] !== NULL): ?>
          <span class="b wait"><?= (int) $w['wait_free_hours'] ?>시간 후 무료</span>
        <?php endif; ?>
        <?php if ($w['status'] !== 'ongoing'): ?>
          <span class="b rest"><?= html_escape($STATUS[$w['status']] ?? $w['status']) ?></span>
        <?php endif; ?>
      </div>
      <div class="meta"><?= (int) $w['ep_count'] ?>화</div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2>회차가 많은 작품</h2>
<ol>
<?php foreach ($top as $w): ?>
  <li>
    <a href="/l/<?= (int) $w['id'] ?>"><?= html_escape($w['title']) ?></a>
    <span class="meta"><?= (int) $w['ep_count'] ?>화 ·
      <?= html_escape($STATUS[$w['status']] ?? $w['status']) ?></span>
  </li>
<?php endforeach; ?>
</ol>
<p class="meta" style="margin-top:.5rem">
  조회수나 평점이 아니라 <strong>회차 수</strong>로 줄을 세웁니다 —
  조사한 두 플랫폼 모두 조회수를 공개하지 않았고,
  <strong>없는 지표를 화면에 지어내지 않기 위해서</strong>입니다.
</p>

<h2>최근 올라온 회차</h2>
<table>
  <tr><th>작품</th><th>회차</th><th>공개</th><th></th></tr>
<?php foreach ($recent as $e): ?>
  <tr>
    <td><a href="/l/<?= (int) $e['work_id'] ?>"><?= html_escape($e['title']) ?></a></td>
    <td><?= (int) $e['seq'] ?>화</td>
    <td class="meta"><?= html_escape(substr((string) $e['published_at'], 0, 10)) ?></td>
    <td><span class="b <?= $e['is_charged'] ? 'paid' : 'free' ?>"><?= $e['is_charged'] ? '유료' : '무료' ?></span></td>
  </tr>
<?php endforeach; ?>
</table>

<div class="note">
  <strong>이 화면이 보여 주는 것은 디자인이 아니라 스키마입니다.</strong>
  웹툰 서비스를 만드는 프로젝트가 아니라, <strong>전환을 측정할 대상</strong>이
  필요해서 도메인을 공개 자료로 역추론한 결과입니다.
  <ul style="margin:.6rem 0 0;padding-left:1.1rem">
    <li>작품이 연재요일을 <strong>여럿</strong> 가집니다 — 그래서
        <code>work_publish_days</code> 가 별도 테이블입니다</li>
    <li>"기다리면 무료" 는 <strong>작품</strong> 속성, 무료/유료는
        <strong>회차</strong> 속성입니다 — 붙는 자리가 다릅니다</li>
    <li>연령등급이 <code>boolean</code> 이 아니라 코드입니다 —
        국가마다 등급 체계가 달라 참/거짓으로는 담기지 않습니다</li>
    <li>언어·문자·지역이 <strong>다른 축</strong>입니다 — 간체와 번체가
        둘 다 <code>zh</code> 인데 문자로 갈립니다</li>
  </ul>
  <p style="margin:.6rem 0 0">
    작품을 누르면 <strong>광고 랜딩</strong>으로 갑니다 —
    거기서부터가 이 프로젝트의 본론입니다.
    <a href="https://lp.<?= html_escape($shop) ?>/go?work=1&amp;pid=home&amp;utm_source=home&amp;utm_medium=internal"
       style="color:#7fb3ff">광고 클릭을 흉내 내 보기 →</a>
  </p>
</div>

<footer>
  읽기 대상 <code><?= html_escape($read_target) ?></code> ·
  상관 ID <?= html_escape($trace_id) ?> ·
  <a href="https://github.com/http1220/touchpoint" style="color:#5b6270">소스</a> ·
  <a href="/privacy" style="color:#5b6270">개인정보처리방침</a>
</footer>

</div>
</body>
</html>
