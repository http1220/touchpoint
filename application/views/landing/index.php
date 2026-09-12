<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="ko">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php $this->load->view('partials/gtag'); ?>
<title>touchpoint</title>
<style>
  body{font:14px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:4rem 2rem;
       background:#0f1115;color:#e6e8ec}
  main{max-width:34rem;margin:0 auto}
  h1{font-size:1.05rem;margin:0 0 .4rem}
  p{color:#8b93a1;margin:0 0 .5rem}
  a{color:#7aa2f7}
  code{background:#1a1e27;padding:.1rem .35rem;border-radius:3px}
</style>
<main>
  <h1>touchpoint</h1>
  <p>유입·전환 추적 파이프라인.</p>
  <p>읽기 대상 <code><?= html_escape($read_target) ?></code> · 상관 ID <code><?= html_escape($trace_id) ?></code></p>
  <p><a href="/diag">인프라 진단</a></p>
</main>
</html>
