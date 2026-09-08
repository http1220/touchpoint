<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="ko">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>touchpoint · 인프라 진단</title>
<style>
  body{font:14px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:2rem;
       background:#0f1115;color:#e6e8ec}
  main{max-width:44rem;margin:0 auto}
  h1{font-size:1.1rem;margin:0 0 .25rem}
  .sub{color:#8b93a1;margin:0 0 1.5rem}
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:.5rem .25rem;border-bottom:1px solid #232733;vertical-align:top}
  th{color:#8b93a1;font-weight:400;width:11rem}
  code{background:#1a1e27;padding:.1rem .35rem;border-radius:3px;word-break:break-all}
  .ok{color:#5ec27a} .warn{color:#e0b341}
  .note{margin-top:1.75rem;padding:.9rem 1rem;background:#171b23;border-left:2px solid #3a4152;color:#a8b0bd}
</style>
<main>
  <h1>touchpoint</h1>
  <p class="sub">인프라 진단 · CodeIgniter <?= html_escape(CI_VERSION) ?></p>

  <table>
    <?php foreach ($rows as $k => $v): ?>
      <tr><th><?= html_escape($k) ?></th><td><code><?= html_escape((string) $v) ?></code></td></tr>
    <?php endforeach; ?>
    <tr>
      <th>TLS</th>
      <td><?= ($this->input->server('HTTPS') OR $this->input->server('REQUEST_SCHEME') === 'https')
            ? '<span class="ok">발급됨 — 브라우저 자물쇠 표시를 함께 확인하세요</span>'
            : '<span class="warn">HTTP. ACME 발급이 아직 끝나지 않았거나 실패했습니다</span>' ?></td>
    </tr>
  </table>

  <div class="note">
    <strong>확인할 것</strong><br>
    호스트 4개(<code>lp.</code> <code>m.</code> <code>app.</code> <code>api.</code>)가 모두 자물쇠 표시로 열리는지 ·
    역할이 올바르게 판별되는지 · <code>읽기 대상</code>이 요청마다 rdb1/rdb2 로 갈리는지 ·
    DevTools의 Application &gt; Cookies에서 <code>tp_probe_*</code> 쿠키의
    <code>SameSite</code>·<code>Secure</code>·<code>Partitioned</code> 속성이
    <code>docs/domains-and-cookies.md</code> 3장의 정책표와 일치하는지.
  </div>
</main>
</html>
