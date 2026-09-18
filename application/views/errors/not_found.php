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
<main class="stage">
  <div class="sheet layout-404">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <section class="panel panel--title panel--bottom" aria-labelledby="page-title">
      <p class="eyebrow">404</p>
      <h1 id="page-title">이 주소에는 화면이 없습니다</h1>
      <p>요청한 경로가 없습니다. 시연은 세 화면에서 이어집니다.</p>
    </section>

    <a class="panel panel--route" href="<?= html_escape(tp_host_url('root', '/')) ?>">
      <span class="step__num" aria-hidden="true">①</span>
      <span class="step__name">홈</span>
      <span class="step__desc">측정 대상인 웹툰 서비스 모형</span>
    </a>
    <a class="panel panel--route" href="<?= html_escape(tp_host_url('lp', '/l/1')) ?>">
      <span class="step__num" aria-hidden="true">②</span>
      <span class="step__name">작품 랜딩</span>
      <span class="step__desc">광고 유입이 최초·마지막 접점으로 남는 곳</span>
    </a>
    <a class="panel panel--route" href="<?= html_escape(tp_host_url('app', '/metrics')) ?>">
      <span class="step__num" aria-hidden="true">③</span>
      <span class="step__name">지표</span>
      <span class="step__desc">매체 전송 결과를 분모와 함께</span>
    </a>
  </div>
</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
