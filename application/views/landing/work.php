<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 작품 랜딩. 광고를 누른 사람이 도착하는 화면이다.
 *
 * 시트 두 장이다 — 넓은 가로 화면에서는 4:3 판, 세로 폰에서는 칸이 한 줄.
 *   1  서비스 — 작품 머리 · 이번 요청 · 다른 작품
 *   2  기록   — 최초 접점 · 마지막 접점 · 수집 이벤트 · 실험
 *
 * 없는 번호에도 열린다. 방문·유입 기록은 작품과 무관하게 남는다 → Work_model::find
 *
 * 다른 작품 배너의 data-imp-* 속성과 api./click 링크는 수집이 걸려 있다. 모양만 바꾸고 건드리지 않는다.
 *
 * @var string     $work        URL 의 번호
 * @var array|null $work_row    작품. 없으면 NULL
 * @var array      $touchpoints first/last Touchpoint 또는 NULL
 */
$fields = array(
	'pid' => 'pid', 'subpid' => 'subpid', 'channel' => 'channel',
	'utmSource' => 'utm_source', 'utmMedium' => 'utm_medium',
	'utmCampaign' => 'utm_campaign', 'utmContent' => 'utm_content',
	'utmTerm' => 'utm_term', 'gclid' => 'gclid', 'fbclid' => 'fbclid',
);

$AGE = array('all' => '전체', '12' => '12+', '15' => '15+', '19' => '19+');

$STATUS = array('ongoing' => '연재중', 'finished' => '완결', 'rest' => '휴재');

$repo = 'https://github.com/http1220/touchpoint/blob/main/';

$id = (int) $work;

// 화면 문구는 한국어다. 제목·시놉시스만 작품 언어를 따른다
$title_lang = ($work_row !== NULL && $work_row['lang'] !== 'ko') ? ' lang="'.html_escape($work_row['lang']).'"' : '';

$page_title = $work_row !== NULL ? $work_row['title'] : '작품 '.$work;
?>
<!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => $page_title.' · touchpoint')); ?>
<?php $this->load->view('partials/gtag'); ?>
<?php $this->load->view('partials/meta_pixel'); ?>
</head>
<body>
<main class="stage">

  <!-- ── 시트 1 · 서비스 ───────────────────────── -->
  <div class="sheet layout-landing-1">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => 2)); ?>

    <header class="masthead">
      <p class="masthead__mark">touchpoint <span class="masthead__sub">웹툰</span></p>
      <p class="eyebrow masthead__note">광고 랜딩</p>
    </header>

    <div class="panel work-cover cover-<?= $id % 6 ?>" aria-hidden="true">
      <span class="work-cover__label">#<?= html_escape($work) ?></span>
      <span class="work-cover__note">표지 자리표시</span>
    </div>

    <section class="panel panel--bottom work-info" aria-labelledby="page-title">
      <p class="eyebrow">작품 #<?= html_escape($work) ?></p>
      <?php if ($work_row === NULL): ?>
        <h1 id="page-title">이 번호의 작품은 없습니다</h1>
        <p>그래도 방문과 유입은 기록됩니다 — 아래 기록 시트에 남은 것이 그것입니다.</p>
      <?php else: ?>
        <h1 id="page-title"<?= $title_lang ?>><?= html_escape($work_row['title']) ?></h1>
        <p class="work-info__meta">
          <span class="badge"><?= html_escape($AGE[$work_row['age_rating_code']] ?? $work_row['age_rating_code']) ?></span>
          <?php if ($work_row['wait_free_hours'] !== NULL): ?>
            <span class="badge"><?= (int) $work_row['wait_free_hours'] ?>시간 후 무료</span>
          <?php endif; ?>
          <span class="badge"><?= html_escape($STATUS[$work_row['status']] ?? $work_row['status']) ?></span>
          <span class="work__eps"><?= (int) $work_row['ep_count'] ?>화</span>
        </p>
        <?php if ((string) $work_row['synopsis'] !== ''): ?>
          <p class="work-info__synopsis"<?= $title_lang ?>><?= html_escape($work_row['synopsis']) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="panel visit-now" aria-labelledby="visit-now-title">
      <p class="caption caption--corner">
        <?php if ($via_bridge): ?>
          광고 유입은 <strong>브리지(<code>/go</code>)가 먼저 기록</strong>하고, 랜딩에는 방문 번호(<code>vid</code>)만 넘깁니다 — 공유된 주소가 광고 클릭으로 다시 세어지지 않게.
          <a href="<?= $repo ?>docs/failure-scenarios.md">C-2<span aria-hidden="true">↗</span></a>
        <?php else: ?>
          방금 이 요청이 기록한 것입니다. 전체는 아래 기록 시트에.
        <?php endif; ?>
      </p>
      <h2 id="visit-now-title" class="visit-now__title">이번 요청</h2>
      <dl class="facts">
        <dt>방문</dt>
        <dd><span class="badge<?= $is_new ? ' badge--strong' : '' ?>"><?= $is_new ? '새 방문' : '기존 방문' ?></span></dd>
        <dt>이번 요청</dt>
        <dd>
          <?php if ( ! $result['is_direct']): ?>유입 파라미터 있음
          <?php elseif ($via_bridge): ?>브리지에서 넘어옴 — 이 요청엔 <code>vid</code> 만
          <?php else: ?>파라미터 없음 — 직접 유입
          <?php endif; ?>
        </dd>
        <dt>최초 접점</dt>
        <dd><span class="badge<?= $result['first'] === 'created' ? ' badge--strong' : '' ?>"><code><?= html_escape($result['first']) ?></code></span></dd>
        <dt>마지막 접점</dt>
        <dd><span class="badge<?= $result['last'] === 'updated' ? ' badge--strong' : '' ?>"><code><?= html_escape($result['last']) ?></code></span></dd>
      </dl>
    </section>

    <?php if ( ! empty($related) && $api_host !== ''): ?>
    <section class="panel related" aria-labelledby="related-title">
      <h2 id="related-title">다른 작품</h2>
      <?php /* data-imp 가 붙은 배너가 화면에 절반 이상 보이면 track.js 가 노출로 모은다.
               링크는 api./click 을 거쳐 랜딩으로 간다. sd 는 이 화면을 그린 날 → ClickRequest */ ?>
      <ul class="works">
        <?php foreach ($related as $w): ?>
          <?php $dest = tp_host_url('lp', '/l/'.(int) $w['id']); ?>
          <li>
            <a class="work work--small" data-imp-work="<?= (int) $w['id'] ?>" data-imp-slot="lp_related"
               href="https://<?= html_escape($api_host) ?>/click?<?= html_escape(http_build_query(array('w' => (int) $w['id'], 's' => 'lp_related', 'sd' => $stat_date, 'u' => $dest))) ?>">
              <span class="work__cover cover-<?= (int) $w['id'] % 6 ?>" aria-hidden="true">#<?= (int) $w['id'] ?></span>
              <span class="work__body">
                <span class="work__title"><?= html_escape($w['title']) ?></span>
                <span class="work__meta"><span class="work__eps"><?= (int) $w['ep_count'] ?>화</span></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="caption">화면에 <strong>절반 이상 보이면 노출</strong>, 누르면 클릭입니다. 클릭은 누른 날이 아니라 <strong>노출된 날</strong>로 셉니다.
        <a href="<?= $repo ?>docs/api-spec.md">api-spec.md<span aria-hidden="true">↗</span></a></p>
    </section>
    <?php endif; ?>
  </div>

  <!-- ── 시트 2 · 기록 ─────────────────────────── -->
  <div class="sheet layout-landing-record record">
    <section class="panel record-head" aria-labelledby="record-title">
      <h2 id="record-title">기록</h2>
      <p class="meta-line">
        방문 <code><?= html_escape($visit_uid) ?></code> ·
        읽기 대상 <code><?= html_escape($read_target) ?></code> (복제본) ·
        상관 ID <code><?= html_escape($trace_id) ?></code>
      </p>
      <p class="caption">
        <strong>확인할 것</strong> — 유입 파라미터를 붙여 <code>/go?work=<?= html_escape($work) ?>&amp;pid=google&amp;utm_source=google</code> 로 들어온 뒤
        파라미터 없이 이 주소를 다시 열어 보세요. <strong>최초·마지막 접점이 그대로 남아야 합니다</strong> — 직접 유입이 광고 성과를 지우면 안 됩니다.
        <a href="<?= $repo ?>docs/failure-scenarios.md">failure-scenarios.md C-2<span aria-hidden="true">↗</span></a>
      </p>
    </section>

    <?php foreach (array('first' => '최초 접점', 'last' => '마지막 접점') as $pos => $label): ?>
      <section class="panel touch touch--<?= $pos ?>" aria-labelledby="touch-<?= $pos ?>">
        <h3 id="touch-<?= $pos ?>"><?= html_escape($label) ?> <code class="touch__key"><?= $pos ?></code></h3>
        <?php if ($touchpoints[$pos] === NULL): ?>
          <p class="empty">아직 없습니다.</p>
        <?php else: ?>
          <?php
            $tp = $touchpoints[$pos];
            $filled = array();
            foreach ($fields as $prop => $shown)
            {
                if ($tp->{$prop} !== NULL) { $filled[$shown] = $tp->{$prop}; }
            }
          ?>
          <table class="data kv">
            <tbody>
              <?php foreach ($filled as $shown => $v): ?>
                <tr><th scope="row"><?= html_escape($shown) ?></th><td><code><?= html_escape($v) ?></code></td></tr>
              <?php endforeach; ?>
              <tr><th scope="row">기록 시각</th><td><code><?= html_escape($tp->occurredAt->format('c')) ?></code></td></tr>
            </tbody>
          </table>
          <p class="empty">
            <?php if ($filled === array()): ?>유입 소스가 하나도 없습니다 — 직접 유입.<?php endif; ?>
            나머지 <?= count($fields) - count($filled) ?>개 필드는 비어 있습니다.
          </p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <section class="panel events" aria-labelledby="events-title">
      <h3 id="events-title">수집 이벤트 <span class="muted">최근 10</span></h3>
      <p class="caption">복제본 <code><?= html_escape($read_target) ?></code> 에서 읽었습니다. 방금 보낸 것이 안 보이면 <strong>복제 지연</strong>입니다 — 새로고침해 보세요.
        <a href="<?= $repo ?>docs/decisions/ADR-007-read-write-split.md">ADR-007<span aria-hidden="true">↗</span></a></p>
      <?php if ($events === array()): ?>
        <p class="empty">아직 없습니다.</p>
      <?php else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="수집 이벤트 표 — 가로로 스크롤">
          <table class="data data--tight">
            <thead><tr><th scope="col">이벤트</th><th scope="col">전송</th><th scope="col">오리진</th><th scope="col">발생(클라)</th><th scope="col">수신(서버)</th></tr></thead>
            <tbody>
              <?php foreach ($events as $e): ?>
                <tr>
                  <td><code><?= html_escape($e['event']) ?></code></td>
                  <td><span class="badge"><?= html_escape($e['transport']) ?></span></td>
                  <td><code><?= html_escape((string) $e['origin']) ?></code></td>
                  <td><code><?= html_escape($e['occurred_at']) ?></code></td>
                  <td><code><?= html_escape($e['received_at']) ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel experiment" aria-labelledby="experiment-title">
      <h3 id="experiment-title">두 전송 경로를 눌러 보세요</h3>
      <div class="buttons">
        <button type="button" class="button" onclick="tp.track('click', {}, 'fetch')">fetch 로 click</button>
        <button type="button" class="button" onclick="tp.track('click', {}, 'beacon')">beacon 으로 click</button>
        <button type="button" class="button" onclick="tp.flushImpressions(false)">본 배너 노출 지금 보내기</button>
      </div>
      <p class="caption">DevTools Network 탭에서 <strong><code>fetch</code> 에는 OPTIONS 가 앞에 붙고 <code>beacon</code> 에는 안 붙습니다.</strong>
        <code>beacon</code> 이 <code>text/plain</code> 을 쓰는 이유이자, 헤더를 못 붙이는 대가입니다.
        <a href="<?= $repo ?>docs/failure-scenarios.md">failure-scenarios.md B-1 · B-3<span aria-hidden="true">↗</span></a></p>
    </section>
  </div>

</main>

<?php $this->load->view('partials/footer', array('privacy_label' => '개인정보처리방침 · 맞춤형 광고 거부')); ?>

<?php if ($api_host !== ''): ?>
  <script src="https://<?= html_escape($api_host) ?>/track.js" data-work="<?= html_escape($work) ?>"></script>
<?php endif; ?>
</body>
</html>
