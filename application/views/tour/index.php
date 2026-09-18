<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 시연 안내. 홈에서 옮겨 왔다.
 *
 * 시트 두 장.
 *   1  성공 기준 한 문장 + 흐름도 일곱 걸음 (광고 클릭 → … → 결제 → PG 웹훅 → … → 매체)
 *   2  어려운 자리는 여기다 (5열) · 귀속은 이렇게 정해진다 (7열)
 *
 * 흐름도는 SVG 가 아니라 글자와 선으로 그린다 — 좁은 화면에서 글자가 줄어들지 않고,
 * 스크린리더가 순서를 목록으로 읽는다. 화살표는 CSS 가 그리고 보조기술에서는 숨긴다.
 *
 * **이 화면은 시스템 설명이다.** 측정 대상(홈)의 스키마 해설은 여기 있다가 docs 로 갔다 —
 * 마지막 자리는 귀속 규칙이 쓴다(2026-09-18).
 *
 * @var string $trace_id
 */
$repo = 'https://github.com/http1220/touchpoint/blob/main/';

$go = tp_host_url('lp', '/go?work=1&pid=tour&utm_source=tour&utm_medium=internal');

/**
 * 파이프라인 일곱 걸음 — 광고 클릭에서 매체 보고서까지. 링크가 있는 걸음은 실제로 그 화면으로 간다.
 *
 * 결제와 웹훅을 넣은 이유: 이 시스템의 한 줄 설명이 "결제까지 가는 길을 추적한다" 인데
 * 흐름도가 랜딩에서 전환으로 건너뛰면 **정작 결제가 어디서 일어나는지 화면이 말하지 않는다**.
 * 마지막 걸음도 "보냈다" 가 아니라 **"매체 보고서에 남았다"** 로 끝난다 — 되돌려 보내는 것이
 * 목적이므로 도착을 확인해야 이야기가 닫힌다 (2026-09-18).
 */
$flow = array(
	array('lane' => '브라우저', 'name' => '광고 클릭', 'href' => $go,
	      'sub'  => '유입 파라미터를 달고 <code>/go</code> 로. 눌러 보세요'),
	array('lane' => '서버', 'name' => '브리지 302', 'href' => NULL,
	      'sub'  => '여기서 최초·마지막 유입이 남는다. 랜딩에는 방문 번호만 넘긴다'),
	array('lane' => '브라우저', 'name' => '작품 랜딩', 'href' => tp_host_url('lp', '/l/1'),
	      'sub'  => '무엇이 기록됐는지 화면이 보여 준다. <code>track.js</code> 가 노출·클릭을 모은다'),
	array('lane' => '브라우저', 'name' => '결제', 'href' => NULL,
	      'sub'  => '<code>/purchase</code> — 금액의 진실은 서버 상품표에 있다. 브라우저가 부른 값을 믿지 않는다'),
	array('lane' => '서버', 'name' => 'PG 웹훅', 'href' => NULL,
	      'sub'  => '결제 확정은 <strong>웹훅으로</strong> 온다. 같은 알림이 두 번 와도 한 번만 센다'),
	array('lane' => '서버', 'name' => '전환 → 아웃박스', 'href' => NULL,
	      'sub'  => '전환과 보낼 것을 <strong>같은 트랜잭션</strong>에 적는다. 어느 광고에서 왔는지가 여기서 붙는다'),
	array('lane' => '매체', 'name' => '워커 → GA4·Meta', 'href' => tp_host_url('app', '/metrics'),
	      'sub'  => '보낸 뒤 <strong>매체를 되읽어</strong> 보고서에 남았는지 맞댄다 — 543/543, +40h'),
);
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '시연 안내 · touchpoint')); ?>
</head>
<body>
<main class="stage">
  <div class="sheet layout-tour">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => 0)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">시연 안내</span></p>
      <p class="eyebrow masthead__note">광고 클릭에서 매체 보고서까지</p>
    </header>

    <section class="panel tour-intro" aria-labelledby="page-title">
      <h1 id="page-title">광고 클릭에서 GA4 보고서까지 실제로 돌려 봤고, 숫자는 <span class="nowrap">분모·표본·측정 시각과</span> 함께만 보여 줍니다.</h1>
      <p class="caption caption--corner">
        홈은 <strong>측정할 대상</strong>으로 만든 웹툰 서비스 모형입니다. 작품·회차는 실제 DB 이고, 표지는 자리표시입니다.
      </p>

      <ol class="flow">
        <?php foreach ($flow as $s): ?>
          <li class="flow__step">
            <?php if ($s['href'] !== NULL): ?>
              <a class="flow__box" href="<?= html_escape($s['href']) ?>">
                <span class="flow__lane"><?= html_escape($s['lane']) ?></span>
                <span class="flow__name"><?= html_escape($s['name']) ?></span>
                <span class="flow__sub"><?= $s['sub'] ?></span>
              </a>
            <?php else: ?>
              <div class="flow__box">
                <span class="flow__lane"><?= html_escape($s['lane']) ?></span>
                <span class="flow__name"><?= html_escape($s['name']) ?></span>
                <span class="flow__sub"><?= $s['sub'] ?></span>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>

      <p class="meta-line"><strong>밑줄 친 세 칸만 화면이 있습니다.</strong> 나머지 넷은 서버와 API 라 눌러 볼 것이 없고, 그 결과를 랜딩과 지표에서 봅니다.
        결제도 화면이 아니라 <code>/purchase</code> 로 시연합니다 — 가짜 PG 로 웹훅까지 한 바퀴 돕니다.</p>
    </section>
  </div>

  <!-- ── 시트 2 · 어려운 자리 · 귀속 결정 ───────────────────── -->
  <div class="sheet layout-tour">

    <?php /* 흐름도는 잘 되는 길만 그린다. 이 시스템의 값어치는 그 길이 어긋날 때 무엇을 하느냐에 있다 */ ?>
    <section class="panel tour-hard" aria-labelledby="hard-title">
      <h2 id="hard-title">어려운 자리는 여기다</h2>
      <p class="meta-line">흐름도는 잘 풀린 한 바퀴다. 실제로 시간을 쓴 곳은 그 길이 어긋나는 자리였다.</p>
      <div class="captions">
        <p class="caption"><strong>파라미터 없이 다시 들어와도 최초 유입을 덮지 않는다.</strong> 직접 유입은 마지막 유입만 만든다 — 광고 공을 나중 방문이 가로채지 않게.
          <a href="<?= $repo ?>docs/failure-scenarios.md">failure-scenarios.md<span aria-hidden="true">↗</span></a></p>
        <p class="caption"><strong>같은 결제 알림이 두 번 와도 한 번만 센다.</strong> <code>dedup_key</code> 에 UNIQUE 를 걸어 DB 가 막는다. 막힌 건 행을 남기지 않아 화면이 셀 수 없다는 것도 적어 뒀다.</p>
        <p class="caption"><strong>전환과 보낼 것을 같은 트랜잭션에 적는다.</strong> 따로 적으면 "결제는 됐는데 매체에 안 간" 건이 조용히 생긴다.
          <a href="<?= $repo ?>docs/decisions/ADR-003-mysql-outbox.md">ADR-003<span aria-hidden="true">↗</span></a></p>
        <p class="caption"><strong>매체가 <code>2xx</code> 를 줘도 아직 반영된 게 아니다.</strong> 되읽어 맞대 보니 40시간이 걸렸고, 그 사이 두 번 잘못 판정했다.
          <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 5-1<span aria-hidden="true">↗</span></a></p>
        <p class="caption">이런 자리를 <strong>12건</strong> 적어 놓고 하나씩 재현했다.
          <a href="<?= $repo ?>docs/failure-scenarios.md">실패 시나리오 12건<span aria-hidden="true">↗</span></a></p>
      </div>
    </section>

    <?php /* 이 화면의 마지막 자리는 시스템 이야기여야 한다. 웹툰 스키마 해설 넉 줄이 여기 있었는데,
             그건 측정 대상(홈)의 사실이지 이 시스템의 설명이 아니었다 — docs 로 보내고 귀속으로 바꿨다 (2026-09-18) */ ?>
    <section class="panel tour-attr" aria-labelledby="attr-title">
      <h2 id="attr-title">귀속은 이렇게 정해진다</h2>
      <p class="meta-line">광고 성과가 조용히 사라지는 자리가 여기다. 규칙을 코드에 적어 두고 화면이 그대로 말한다.</p>

      <table class="data data--tight">
        <caption class="sr-only">들어온 접점에 따른 최초·마지막 유입 갱신 규칙 네 가지</caption>
        <thead><tr><th scope="col">들어온 접점</th><th scope="col">지금 상태</th><th scope="col">하는 일</th></tr></thead>
        <tbody>
          <tr><td>유입 파라미터 있음</td><td>최초 없음</td><td>최초를 만들고 마지막도 갱신</td></tr>
          <tr><td>유입 파라미터 있음</td><td>최초 있음</td><td><strong>최초는 보존</strong>, 마지막만 갱신</td></tr>
          <tr><td>직접 유입</td><td>마지막 있음</td><td><strong>아무것도 바꾸지 않는다</strong></td></tr>
          <tr><td>직접 유입</td><td>마지막 없음</td><td>마지막만 기록 <span class="muted">(최초로는 삼지 않는다)</span></td></tr>
        </tbody>
      </table>

      <div class="captions">
        <p class="caption"><strong>셋째 줄이 핵심입니다.</strong> 광고를 타고 온 사람이 나중에 북마크로 다시 오면 직접 유입입니다.
          이때 마지막 유입을 덮으면 그 사람의 결제는 <strong>어느 매체에도 붙지 않습니다</strong> — 매체 대시보드와 자체 집계가 어긋나는 흔한 원인입니다.
          <a href="<?= $repo ?>src/Attribution/TouchpointResolver.php">TouchpointResolver<span aria-hidden="true">↗</span></a></p>
        <p class="caption"><strong>결제에는 한 층이 더 있습니다.</strong> 결제 시점의 방문이 있으면 그쪽, 없으면 가입 접점으로 떨어집니다 —
          가입은 A 광고로 하고 석 달 뒤 B 광고를 보고 돌아와 결제할 수 있으니까요.
          전에는 <strong>고를 수 없었습니다</strong> — 결제에 방문을 적을 칸이 없어 무조건 가입 접점이었습니다.
          <a href="<?= $repo ?>application/migrations/20260914000100_add_payment_visit.php">방문 칸을 더한 마이그레이션<span aria-hidden="true">↗</span></a></p>
        <p class="caption">그래서 지표 화면은 <strong>한 기준을 고르지 않고 둘을 나란히</strong> 냅니다 — 같은 결제도 최초 기준과 마지막 기준에서 다른 매체에 붙습니다.
          <a href="<?= html_escape(tp_host_url('app', '/metrics#attribution')) ?>">광고가 만든 것<span aria-hidden="true">↗</span></a></p>
        <p class="caption">홈의 웹툰 데이터 모델(연재 요일 · 기다리면 무료 · 언어와 문자 · 순위 기준)은 화면에서 덜어 내고 문서에 뒀습니다.
          <a href="<?= $repo ?>docs/data-model.md">data-model.md 6장<span aria-hidden="true">↗</span></a> ·
          <a href="<?= $repo ?>docs/research-method.md">research-method.md 3-1<span aria-hidden="true">↗</span></a></p>
      </div>
    </section>
  </div>
</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
