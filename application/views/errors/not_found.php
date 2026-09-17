<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** @var string $trace_id */
?>
<!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '404 · touchpoint')); ?>
</head>
<body>
<?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

<main class="page page--narrow">
  <p class="eyebrow">404</p>
  <h1>이 주소에는 화면이 없습니다</h1>
  <p>요청한 경로가 없습니다. 시연은 아래 세 화면에서 이어집니다.</p>

  <ol class="route-list">
    <li>
      <a href="<?= html_escape(tp_host_url('root', '/')) ?>">
        <span class="route-list__num" aria-hidden="true">①</span>
        <span class="route-list__name">홈</span>
        <span class="route-list__desc">측정 대상인 웹툰 서비스 모형</span>
      </a>
    </li>
    <li>
      <a href="<?= html_escape(tp_host_url('lp', '/l/1')) ?>">
        <span class="route-list__num" aria-hidden="true">②</span>
        <span class="route-list__name">작품 랜딩</span>
        <span class="route-list__desc">광고 유입이 최초·마지막 유입으로 남는 곳</span>
      </a>
    </li>
    <li>
      <a href="<?= html_escape(tp_host_url('app', '/metrics')) ?>">
        <span class="route-list__num" aria-hidden="true">③</span>
        <span class="route-list__name">지표</span>
        <span class="route-list__desc">매체 전송 결과를 분모와 함께</span>
      </a>
    </li>
  </ol>
</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
