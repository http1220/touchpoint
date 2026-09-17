<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 인프라 진단. 라우트 주석은 "개발 환경 전용" 이지만 운영에서도 열린다(어디서도 링크하지 않는다).
 *
 * **GA4 태그는 남기고 Meta 픽셀은 뺐다.** 이 화면이 보여 주는 것은 `_ga 쿠키`와 `client_id` 뿐이라
 * gtag 는 그 두 줄을 위해 필요하지만, `_fbp`·`_fbc` 는 어느 줄에도 쓰이지 않는다 —
 * 진단을 열 때마다 Meta 에 조회가 갈 이유가 없다. 남은 GA4 집계는 화면에 적는다.
 *
 * 검색엔진에 올릴 화면이 아니다 → noindex.
 *
 * @var array  $rows
 * @var string $trace_id
 */
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '인프라 진단 · touchpoint')); ?>
<meta name="robots" content="noindex, nofollow">
<?php $this->load->view('partials/gtag'); ?>
</head>
<body>
<main class="stage">
  <div class="sheet layout-diag">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <header class="masthead">
      <p class="masthead__mark">touchpoint <span class="masthead__sub">진단</span></p>
      <p class="eyebrow masthead__note">CodeIgniter <?= html_escape(CI_VERSION) ?></p>
    </header>

    <section class="panel diag-table" aria-labelledby="diag-title">
      <h1 id="diag-title">인프라 진단</h1>
      <table class="data kv">
        <tbody>
          <?php foreach ($rows as $k => $v): ?>
            <tr><th scope="row"><?= html_escape($k) ?></th><td><code><?= html_escape((string) $v) ?></code></td></tr>
          <?php endforeach; ?>
          <tr>
            <th scope="row">TLS</th>
            <td><?= ($this->input->server('HTTPS') OR $this->input->server('REQUEST_SCHEME') === 'https')
                  ? '<span class="badge badge--success">발급됨</span> 브라우저 자물쇠 표시를 함께 확인하세요'
                  : '<span class="badge badge--warning">HTTP</span> ACME 발급이 아직 끝나지 않았거나 실패했습니다' ?></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="panel diag-check" aria-labelledby="check-title">
      <h2 id="check-title">확인할 것</h2>
      <p class="caption">이 화면은 <code>_ga</code> 쿠키를 읽어야 해서 <strong>GA4 태그를 싣는다</strong> — 열면 GA4 에 조회가 한 번 잡힌다.
        Meta 픽셀은 이 화면에 필요 없어 싣지 않는다.</p>
      <p class="caption">
        호스트 4개(<code>lp.</code> <code>m.</code> <code>app.</code> <code>api.</code>)가 모두 자물쇠 표시로 열리는지 ·
        역할이 올바르게 판별되는지 · <code>읽기 대상</code>이 요청마다 rdb1/rdb2 로 갈리는지 ·
        DevTools의 Application &gt; Cookies에서 <code>tp_probe_*</code> 쿠키의
        <code>SameSite</code>·<code>Secure</code>·<code>Partitioned</code> 속성이
        <code>docs/domains-and-cookies.md</code> 3장의 정책표와 일치하는지.
      </p>
    </section>
  </div>
</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
