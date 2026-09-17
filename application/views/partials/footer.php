<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 바닥글 — 모든 화면.
|
| 읽기 대상은 **DB 를 읽은 화면만** 넘긴다. 404 처럼 읽지 않은 화면에 적으면
| 어느 복제본에서 읽었는지를 지어내는 것이 된다.
|
| 방침 링크는 절대 URL 이다 — 방침은 root·lp.·app. 에서만 열린다(m. 에서 상대경로는 404).
|
| @var string      $trace_id
| @var string|null $read_target 없으면 적지 않는다
| @var bool|null   $hide_privacy 방침 화면 자신
| @var string|null $privacy_label 픽셀이 실린 화면(랜딩)은 "맞춤형 광고 거부"까지 적는다 — 거부로 가는 길이 보여야 한다
*/
?>
<footer class="site-footer">
  <p>
    <?php if (isset($read_target)): ?>
      읽기 대상 <code><?= html_escape($read_target) ?></code> (복제본) ·
    <?php endif; ?>
    상관 ID <code><?= html_escape($trace_id) ?></code> ·
    <a href="https://github.com/http1220/touchpoint">소스<span aria-hidden="true">↗</span></a>
    <?php if (empty($hide_privacy)): ?>
      · <a href="<?= html_escape(tp_host_url('root', '/privacy')) ?>"><?= html_escape(isset($privacy_label) ? $privacy_label : '개인정보처리방침') ?></a>
    <?php endif; ?>
  </p>
</footer>
