<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 시연 바 — 모든 화면 맨 위.
|
| PDF 의 링크 세 개가 각각 다른 화면으로 들어온다. 어디로 들어왔든 전체 경로와
| 지금 위치를 먼저 준다. 서비스 층(웹툰 머리)과 섞지 않으려고 해설 층의 목소리로 둔다.
|
| ② 는 파라미터 없는 직접 유입이다. 광고 클릭 흉내는 홈 안내 띠에서만 한다 —
| 경로를 옮겨 다닐 때마다 광고 유입이 쌓이면 지표의 분모가 부푼다.
|
| @var int|null $demo_step 1 홈 · 2 랜딩 · 3 지표 · NULL 표시 없음(방침·404)
*/
$demo_step = isset($demo_step) ? (int) $demo_step : 0;

$steps = array(
	1 => array('①', '홈',   tp_host_url('root', '/')),
	2 => array('②', '랜딩', tp_host_url('lp', '/l/1')),
	3 => array('③', '지표', tp_host_url('app', '/metrics')),
);
?>
<nav class="demo-bar" aria-label="시연 경로">
  <div class="demo-bar__inner">
    <span class="demo-bar__label">시연</span>
    <ol class="demo-bar__steps">
      <?php foreach ($steps as $n => $s): ?>
        <li><a href="<?= html_escape($s[2]) ?>"<?= $n === $demo_step ? ' aria-current="page"' : '' ?>><span aria-hidden="true"><?= $s[0] ?></span> <?= $s[1] ?></a></li>
      <?php endforeach; ?>
    </ol>
    <a class="demo-bar__source" href="https://github.com/http1220/touchpoint">소스<span aria-hidden="true">↗</span></a>
  </div>
</nav>
