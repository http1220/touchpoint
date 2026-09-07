<?php
declare(strict_types=1);

/**
 * D1~D2 임시 진단 페이지.
 *
 * 목적은 하나 — CI4를 올리기 전에 인프라가 맞게 섰는지 확인하는 것이다.
 * 확인 대상: DNS · TLS 발급 · 호스트 3개 라우팅 · HTTP 버전 · 쿠키 속성.
 *
 * D3에서 CodeIgniter 4를 설치하면 이 파일은 CI4의 부트스트랩으로 교체된다.
 * 그때까지만 존재한다.
 */

$host   = $_SERVER['HTTP_HOST'] ?? '(unknown)';
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') ? 'https' : 'http';
$proto  = $_SERVER['SERVER_PROTOCOL'] ?? '?';
$shop   = getenv('SHOP_DOMAIN') ?: '';
$track  = getenv('TRACK_DOMAIN') ?: '';

// 어느 역할의 호스트인지 판별
$role = match (true) {
    $shop  !== '' && $host === "lp.{$shop}"   => ['lp',  '광고주 측 · 랜딩/브리지', 'first-party'],
    $shop  !== '' && $host === "app.{$shop}"  => ['app', '광고주 측 · 서비스/전환', 'first-party'],
    $track !== '' && $host === "api.{$track}" => ['api', '추적 측 · 수집 API',      'third-party'],
    default                                    => ['?',   '알 수 없는 호스트',        '-'],
};

// 쿠키 정책표(docs/domains-and-cookies.md 3장)와 같은 속성으로 시험 발급.
// DevTools > Application > Cookies 에서 속성이 그대로 찍히는지 눈으로 확인한다.
if ($scheme === 'https') {
    if ($role[0] === 'lp' || $role[0] === 'app') {
        setcookie('ab_probe_vid', bin2hex(random_bytes(8)), [
            'domain'   => '.' . $shop,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => true,
            'httponly' => true,
            'expires'  => time() + 3600,
        ]);
    } elseif ($role[0] === 'api') {
        // SameSite=None; Secure 는 PHP의 배열 문법으로 설정한다.
        // Partitioned 는 PHP 8.3의 setcookie가 아직 지원하지 않으므로 헤더로 직접 붙인다.
        header(sprintf(
            'Set-Cookie: ab_probe_tid=%s; Domain=.%s; Path=/; Max-Age=3600; SameSite=None; Secure; HttpOnly; Partitioned',
            bin2hex(random_bytes(8)),
            $track
        ), false);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$rows = [
    '호스트'        => $host,
    '역할'          => "{$role[0]} — {$role[1]}",
    '쿠키 관점'     => $role[2],
    '스킴'          => $scheme,
    'HTTP 버전'     => $proto,
    'SHOP_DOMAIN'   => $shop !== '' ? $shop : '(미설정)',
    'TRACK_DOMAIN'  => $track !== '' ? $track : '(미설정)',
    'PHP'           => PHP_VERSION,
    '서버 시각(UTC)' => gmdate('c'),
];
?>
<!doctype html>
<html lang="ko">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>attribution-bridge · 인프라 진단</title>
<style>
  body{font:14px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:2rem;
       background:#0f1115;color:#e6e8ec}
  main{max-width:44rem;margin:0 auto}
  h1{font-size:1.1rem;margin:0 0 .25rem}
  .sub{color:#8b93a1;margin:0 0 1.5rem}
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:.5rem .25rem;border-bottom:1px solid #232733;vertical-align:top}
  th{color:#8b93a1;font-weight:400;width:11rem}
  code{background:#1a1e27;padding:.1rem .35rem;border-radius:3px}
  .ok{color:#5ec27a} .warn{color:#e0b341}
  .note{margin-top:1.75rem;padding:.9rem 1rem;background:#171b23;border-left:2px solid #3a4152;color:#a8b0bd}
</style>
<main>
  <h1>attribution-bridge</h1>
  <p class="sub">D1~D2 인프라 진단 · CI4 설치 시 교체됩니다</p>

  <table>
    <?php foreach ($rows as $k => $v): ?>
      <tr><th><?= htmlspecialchars($k) ?></th><td><code><?= htmlspecialchars((string) $v) ?></code></td></tr>
    <?php endforeach; ?>
    <tr>
      <th>TLS</th>
      <td><?= $scheme === 'https'
            ? '<span class="ok">발급됨 — 브라우저 자물쇠 표시를 함께 확인하세요</span>'
            : '<span class="warn">HTTP. ACME 발급이 아직 끝나지 않았거나 실패했습니다</span>' ?></td>
    </tr>
  </table>

  <div class="note">
    <strong>확인할 것</strong><br>
    호스트 3개가 모두 자물쇠 표시로 열리는지 · 역할이 올바르게 판별되는지 ·
    DevTools의 Application &gt; Cookies에서 <code>ab_probe_*</code> 쿠키의
    <code>SameSite</code>·<code>Secure</code>·<code>Partitioned</code> 속성이
    <code>docs/domains-and-cookies.md</code> 3장의 정책표와 일치하는지.
  </div>
</main>
</html>
