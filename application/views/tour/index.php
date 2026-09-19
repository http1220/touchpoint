<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 시연 안내. 홈에서 옮겨 왔다.
 *
 * 시트 두 장.
 *   1  성공 기준 한 문장 + 흐름도 일곱 걸음 (광고 클릭 → … → 결제 → PG 확정 → … → 매체)
 *      + 결제를 직접 해 보는 패널 (09-19 — 이니시스 테스트 상점, 입장권 버튼)
 *   2  어려운 자리는 여기다 (5열) · 귀속은 이렇게 정해진다 (7열)
 *
 * 흐름도는 SVG 가 아니라 글자와 선으로 그린다 — 좁은 화면에서 글자가 줄어들지 않고,
 * 스크린리더가 순서를 목록으로 읽는다. 화살표는 CSS 가 그리고 보조기술에서는 숨긴다.
 *
 * **이 화면은 시스템 설명이다.** 측정 대상(홈)의 스키마 해설은 여기 있다가 docs 로 갔다 —
 * 마지막 자리는 귀속 규칙이 쓴다(2026-09-18).
 *
 * @var string $trace_id
 * @var bool   $pay_open 시연 결제가 열려 있는가 (PAY_DEMO_TOKEN)
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
	array('lane' => '브라우저', 'name' => '광고 클릭', 'href' => $go, 'code' => NULL,
	      'sub'  => '유입 파라미터를 달고 <code>/go</code> 로. 눌러 보세요'),
	array('lane' => '서버', 'name' => '브리지', 'href' => NULL,
	      'code' => 'application/controllers/Bridge.php',
	      'sub'  => '광고 링크가 <strong>먼저 들르는 서버</strong>. 유입을 적고 <code>302</code> 로 랜딩에 넘긴다 — 바로 들여보내면 기록할 기회가 없다'),
	array('lane' => '브라우저', 'name' => '작품 랜딩', 'href' => tp_host_url('lp', '/l/1'), 'code' => NULL,
	      'sub'  => '무엇이 기록됐는지 화면이 보여 준다. <code>track.js</code> 가 노출·클릭을 모은다'),
	// 결제는 아래 패널에서 직접 해 볼 수 있다(09-19). 그래서 코드 대신 그 패널로 건다 — 코드 링크는 패널 안에 있다
	array('lane' => 'API', 'name' => '결제', 'href' => '#pay', 'code' => NULL,
	      'sub'  => '<code>POST /purchase</code> — 금액의 진실은 서버 상품표에 있다. 아래에서 직접 해 보세요'),
	// 처음엔 "PG 웹훅" 이었다. 이니시스 카드 결제에는 웹훅이 없다 — 결과를 브라우저가 가져오고 우리가 승인을 요청한다
	array('lane' => 'API', 'name' => 'PG 확정', 'href' => NULL,
	      'code' => 'application/models/Payment_model.php',
	      'sub'  => '가짜 PG 는 <strong>웹훅</strong>, 이니시스는 <strong>복귀 뒤 승인 요청</strong>으로 온다. 어느 문으로 와도 같은 전이를 탄다 — 같은 알림이 두 번 와도 한 번만 센다'),
	array('lane' => '서버', 'name' => '전환 기록 · 발송 적재', 'href' => NULL,
	      'code' => 'application/models/Conversion_model.php',
	      'sub'  => '결제 같은 <strong>값어치 있는 사건</strong>(전환)과 매체로 보낼 것을 <strong>같은 트랜잭션</strong>에 적는다. 어느 광고에서 왔는지가 여기서 붙는다'),
	array('lane' => '매체', 'name' => '워커 → GA4·Meta', 'href' => tp_host_url('app', '/metrics'), 'code' => NULL,
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
      <h1 id="page-title">광고 클릭에서 GA4 보고서까지, <span class="nowrap">실제로 한 바퀴 돌려 봤습니다.</span></h1>
      <p class="meta-line">그리고 숫자는 이렇게만 보여 줍니다 — 비율은 <strong>몇 / 몇 (몇 %)</strong> 으로, 건수가 적으면 <strong>얇은 표본</strong>이라고, 매체를 되읽어 안 값에는 <strong>언제 쟀는지</strong>를 붙여서.</p>
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
              <?php /* 화면이 없는 걸음은 "안 만든 것" 으로 읽힌다. 그 걸음이 도는 코드로 링크를 건다 —
                       누를 것이 없다는 말과 만들지 않았다는 말은 다르다 (2026-09-18) */ ?>
              <div class="flow__box">
                <span class="flow__lane"><?= html_escape($s['lane']) ?></span>
                <span class="flow__name"><?= html_escape($s['name']) ?></span>
                <span class="flow__sub"><?= $s['sub'] ?></span>
                <?php if ($s['code'] !== NULL): ?>
                  <a class="flow__code" href="<?= $repo.html_escape($s['code']) ?>">코드<span aria-hidden="true">↗</span></a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>

      <p class="meta-line"><strong>일곱 걸음 모두 만들어져 돌아갑니다.</strong> 밑줄 친 넷은 화면이 있어 눌러 볼 수 있고,
        나머지 셋은 <strong>서버와 API 라 눌러 볼 화면이 없을 뿐</strong>입니다 — 그 걸음이 도는 <strong>코드로 링크</strong>를 걸어 두었고,
        실제로 돈 결과는 <a href="<?= html_escape(tp_host_url('app', '/metrics#convert')) ?>">지표의 전환 표</a>에 있습니다(결제 · 환불 · 가입).
        결제는 두 길로 돕니다 — 중복·역전을 재는 <strong>가짜 PG</strong>(웹훅까지 한 바퀴)와 <strong>이니시스 테스트 상점</strong>(카드, 아래에서 직접).
        <a href="<?= $repo ?>docs/decisions/ADR-008-stub-pg.md">ADR-008<span aria-hidden="true">↗</span></a> ·
        <a href="<?= $repo ?>docs/plan-multi-pg.md">plan-multi-pg<span aria-hidden="true">↗</span></a></p>
    </section>

    <?php /* 09-19: 흐름도의 '결제' 걸음을 실제 결제대행사로 돌려 보는 자리. 입장권은 누구나 버튼으로 받는다 —
             POST 라 크롤러는 받지 않고, 버튼 옆 문장이 "카드 승인이 실제로 일어난다" 를 먼저 말한다.
             문구는 pay/gate.php 와 같게 둔다 → docs/plan-multi-pg.md C4 */ ?>
    <section class="panel tour-pay" id="pay" aria-labelledby="pay-title">
      <h2 id="pay-title">결제를 직접 해 보세요</h2>
      <?php if ( ! $pay_open): ?>
        <p class="empty">지금은 시연 결제가 열려 있지 않습니다.</p>
      <?php else: ?>
        <p>이니시스 <strong>테스트 상점</strong>으로 연결됩니다. 테스트 상점도 <strong>카드 승인은 실제로 일어나고</strong>,
          이니시스가 당일 자정 전에 자동으로 취소합니다. 카드 정보는 이니시스 결제창에 직접 넣으며 이 사이트를 거치지 않습니다.
          결제창을 열어 보고 카드를 넣지 않고 닫아도 됩니다.</p>
        <form method="post" action="<?= html_escape(tp_host_url('app', '/pay/access')) ?>">
          <p class="buttons"><button type="submit" class="button">입장권 받고 결제 화면으로</button></p>
        </form>
      <?php endif; ?>
      <p class="caption">결과 화면은 장부(결제 이벤트)를 그대로 보여 줍니다. 승인됐는데 장부에 오르지 못하면 그 요청이 승인을 되돌립니다(망취소) —
        <a href="<?= $repo ?>application/controllers/Pay.php">Pay.php<span aria-hidden="true">↗</span></a> ·
        <a href="<?= $repo ?>application/controllers/Purchase.php">Purchase.php<span aria-hidden="true">↗</span></a></p>
    </section>
  </div>

  <!-- ── 시트 2 · 어려운 자리 · 귀속 결정 ───────────────────── -->
  <div class="sheet layout-tour">

    <?php /* 흐름도는 잘 되는 길만 그린다. 이 시스템의 값어치는 그 길이 어긋날 때 무엇을 하느냐에 있다 */ ?>
    <section class="panel tour-hard" aria-labelledby="hard-title">
      <h2 id="hard-title">어려운 자리는 여기다</h2>
      <p class="meta-line">흐름도는 잘 풀린 한 바퀴다. 실제로 시간을 쓴 곳은 그 길이 어긋나는 자리였다.</p>
      <div class="captions">
        <p class="caption"><strong>직접 유입은 <code>first</code> 를 덮지 않는다</strong>(규칙 3·4). 광고 기여가 나중 방문에 가로채이지 않게.
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

      <?php /* 표의 말은 코드의 말과 같아야 한다 — TouchpointResolver 의 규칙 번호와 first/last 를 그대로 쓴다.
               풀어 쓰면 읽기는 쉬워도 **코드와 대조할 수 없다**. 대조할 수 있는 것이 이 화면의 값어치다 */ ?>
      <table class="data data--tight">
        <caption class="sr-only">들어온 접점에 따른 first·last touch 갱신 규칙 네 가지</caption>
        <thead><tr><th scope="col">규칙</th><th scope="col">들어온 접점</th><th scope="col">지금 상태</th><th scope="col">하는 일</th></tr></thead>
        <tbody>
          <tr><td class="n">1</td><td>유입 소스 있음</td><td><code>first</code> 없음</td><td><code>first</code> 생성 + <code>last</code> 갱신</td></tr>
          <tr><td class="n">2</td><td>유입 소스 있음</td><td><code>first</code> 있음</td><td><strong><code>first</code> 보존</strong> · <code>last</code> 만 갱신</td></tr>
          <tr><td class="n">3</td><td>직접 유입</td><td><code>last</code> 있음</td><td><strong>아무것도 바꾸지 않는다</strong></td></tr>
          <tr><td class="n">4</td><td>직접 유입</td><td><code>last</code> 없음</td><td><code>last</code> 만 기록 <span class="muted">(<code>first</code> 로 삼지 않는다)</span></td></tr>
        </tbody>
      </table>

      <div class="captions">
        <p class="caption"><strong>규칙 3이 핵심입니다.</strong> 광고를 타고 온 사람이 나중에 북마크로 다시 오면 직접 유입입니다.
          이때 <code>last</code> 를 덮으면 그 사람의 결제는 <strong>어느 매체에도 귀속되지 않습니다</strong> — 매체 대시보드와 자체 집계가 어긋나는 흔한 원인입니다.
          <a href="<?= $repo ?>src/Attribution/TouchpointResolver.php">TouchpointResolver<span aria-hidden="true">↗</span></a></p>
        <p class="caption"><strong>결제 귀속에는 한 층이 더 있습니다.</strong> 결제 시점 방문이 있으면 그쪽, 없으면 가입 접점(<code>signup_visit_id</code>)으로 떨어집니다 —
          가입은 A 광고로 하고 석 달 뒤 B 광고를 보고 돌아와 결제할 수 있으니까요.
          전에는 <strong>고를 수 없었습니다</strong> — 결제에 방문을 적을 칸이 없어 무조건 가입 접점이었습니다.
          <a href="<?= $repo ?>application/migrations/20260914000100_add_payment_visit.php">방문 칸을 더한 마이그레이션<span aria-hidden="true">↗</span></a></p>
        <p class="caption">그래서 지표 화면은 <strong>한 기준을 고르지 않고 둘을 나란히</strong> 냅니다 — 같은 결제도 first-touch 기준과 last-touch 기준에서 다른 매체에 붙습니다.
          <a href="<?= html_escape(tp_host_url('app', '/metrics#attribution')) ?>">광고가 만든 것<span aria-hidden="true">↗</span></a></p>
        <p class="caption"><strong>접점 · 귀속 · 도달률 · 반영률</strong>이 각각 무엇을 세는 말인지는 <a href="<?= $repo ?>docs/glossary.md">용어<span aria-hidden="true">↗</span></a> 에 모아 두었습니다. 홈의 웹툰 데이터 모델은 화면에서 덜어 내고 문서에 뒀습니다.
          <a href="<?= $repo ?>docs/data-model.md">data-model.md 6장<span aria-hidden="true">↗</span></a> ·
          <a href="<?= $repo ?>docs/research-method.md">research-method.md 3-1<span aria-hidden="true">↗</span></a></p>
      </div>
    </section>
  </div>
</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
