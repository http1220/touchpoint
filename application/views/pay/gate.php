<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 입장권이 없을 때의 결제 화면. 안내(/tour)를 거치지 않고 /pay 로 곧장 온 사람도
 * 여기서 받는다 → controllers/Pay.php showGate()
 *
 * 버튼 옆의 문장이 이 문의 전부다 — "카드 승인이 실제로 일어난다" 를 읽고 누르게 한다.
 * 문구는 안내 화면(tour/index.php)의 결제 패널과 같게 둔다.
 *
 * @var bool $open PAY_DEMO_TOKEN 이 있는가. 없으면 아무도 못 연다
 */
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '시연 결제 · touchpoint')); ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<main class="stage">
  <div class="sheet layout-pay">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">시연 결제</span></p>
    </header>

    <section class="panel" aria-labelledby="gate-title">
      <h1 id="gate-title">결제를 직접 해 보기</h1>
      <?php if ( ! $open): ?>
        <p class="empty">지금은 시연 결제가 열려 있지 않습니다.</p>
      <?php else: ?>
        <p>
          이니시스 <strong>테스트 상점</strong>으로 연결됩니다. 테스트 상점도 <strong>카드 승인은 실제로 일어나고</strong>,
          이니시스가 당일 자정 전에 자동으로 취소합니다. 카드 정보는 이니시스 결제창에 직접 넣으며 이 사이트를 거치지 않습니다.
        </p>
        <form method="post" action="/pay/access">
          <p class="buttons"><button type="submit" class="button">입장권 받고 결제 화면으로</button></p>
        </form>
        <p class="caption">입장권은 이 브라우저에만, 하루 동안 유효합니다. 결제창을 열어 보고 카드를 넣지 않고 닫아도 됩니다.</p>
      <?php endif; ?>
      <p><a href="<?= html_escape(tp_host_url('root', '/tour')) ?>">시연 안내로</a></p>
    </section>
  </div>
</main>
</body>
</html>
