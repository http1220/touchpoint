<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 개인정보처리방침. 서버 렌더 · JS 없음.
 *
 * **내용은 코드에서 옮겼다** — 컨트롤러 주석 참조. 코드가 수집하는 것을
 * 바꾸면 여기도 바꾼다. 표의 각 줄 옆 주석이 근거 위치다.
 *
 * @var string      $shop
 * @var string      $contact
 * @var bool        $opted_out
 * @var bool        $pixel_on
 * @var string|null $done
 */
$repo = 'https://github.com/http1220/touchpoint';
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>개인정보처리방침 — touchpoint</title>
<style>
:root{--bg:#0f1115;--fg:#e6e8ec;--dim:#8b93a1;--line:#232733;--card:#171b23;--accent:#ffd166;--ok:#7fd6a0;--warn:#e0c77f}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:#7fb3ff}
.wrap{max-width:52rem;margin:0 auto;padding:1.5rem 1.25rem 4rem}
header{padding-bottom:1rem;border-bottom:1px solid var(--line)}
header a{color:var(--dim);text-decoration:none;font-size:.85rem}
h1{font-size:1.35rem;margin:.6rem 0 .2rem;text-wrap:balance}
.eff{color:var(--dim);font-size:.85rem;margin:0}
h2{font-size:1.02rem;margin:2.2rem 0 .6rem;padding-top:.4rem}
p,li{color:#c9ced6}
.lead{margin-top:1.2rem;padding:1rem;background:#12161d;border-left:2px solid var(--accent);color:#c9ced6}
.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.88rem;min-width:36rem}
td,th{text-align:left;vertical-align:top;padding:.5rem .6rem .5rem 0;border-bottom:1px solid var(--line)}
th{color:var(--dim);font-weight:400;white-space:nowrap}
code{background:#1a1e27;padding:.05rem .3rem;border-radius:3px;font-size:.85em}
.ads{margin-top:.8rem;padding:1rem;background:var(--card);border:1px solid var(--line);border-radius:6px}
.state{display:inline-block;padding:.1rem .5rem;border-radius:3px;font-size:.82rem;font-weight:600}
.state.on{background:#1f3a2a;color:var(--ok)} .state.off{background:#3a3520;color:var(--warn)}
.btn{display:inline-block;margin-top:.7rem;padding:.45rem .9rem;border:1px solid #3a4152;border-radius:4px;
     color:var(--fg);text-decoration:none;background:#1a1e27}
.btn:hover,.btn:focus-visible{border-color:var(--accent);outline:none}
.done{margin:.8rem 0 0;color:var(--ok);font-size:.9rem}
footer{margin-top:3rem;color:#5b6270;font-size:.8rem}
</style>
</head>
<body>
<div class="wrap">

<header>
  <a href="https://<?= html_escape($shop) ?>/">← touchpoint 홈</a>
  <h1>개인정보처리방침</h1>
  <p class="eff">시행일 2026년 9월 15일</p>
</header>

<div class="lead">
  이 사이트는 <strong>개인이 운영하는 채용 포트폴리오 시연 사이트</strong>입니다.
  광고 유입부터 전환까지를 추적하는 구조를 보여 주기 위해 만들었고,
  <strong>실제 회원가입과 결제(청구)는 없습니다.</strong>
  아래 내용은 이 사이트의 코드가 실제로 하는 일을 그대로 적은 것입니다
  (<a href="<?= $repo ?>">소스 공개</a>).
</div>

<h2 id="purpose">1. 무엇을 위해 처리하나요</h2>
<ul>
  <li>광고·링크를 통해 들어온 방문이 어떤 경로로 이어졌는지 측정</li>
  <li>이용 통계 분석 (Google Analytics)</li>
  <li>광고 성과 측정과 맞춤형 광고 (Meta) — <a href="#ads">거부할 수 있습니다</a></li>
  <li>서비스 안정 운영 (세션 유지, 장애 분석)</li>
</ul>

<h2 id="items">2. 어떤 정보를, 얼마나 보관하나요</h2>
<div class="scroll">
<table>
  <tr><th>구분</th><th>항목</th><th>보관 기간</th></tr>
  <?php /* application/migrations/…create_attribution · Visit_model::create */ ?>
  <tr><td>방문 기록</td>
      <td>방문 식별자, 첫 방문 시각, 들어온 페이지 경로, 이전 페이지 주소(Referer), 광고 파라미터(utm·매체 코드)와 광고 클릭 식별자(gclid·fbclid),
          국가·언어, <strong>브라우저 정보(UA)와 IP 주소의 해시값</strong> — 원문은 저장하지 않습니다</td>
      <td>3개월</td></tr>
  <?php /* create_collect_events */ ?>
  <tr><td>페이지 이벤트</td><td>조회·노출·클릭 이벤트, 작품 번호, 배너 자리와 노출일, 발생 시각, 요청을 보낸 사이트 주소</td><td>3개월</td></tr>
  <?php /* create_payment_client_context · Conversion_model payload · ClientContext */ ?>
  <tr><td>광고 전송용 브라우저 정보</td>
      <td>결제·전환이 일어날 때의 <strong>브라우저 정보(UA) 원문, IP 주소 원문</strong>,
          페이지 주소(쿼리 문자열 제외), Meta 쿠키(<code>_fbp</code>·<code>_fbc</code>)<br>
          — 브라우저에서 직접 요청했을 때만, <a href="#ads">광고 거부</a> 시에는 수집하지 않습니다</td>
      <td>3개월</td></tr>
  <?php /* create_dispatch — dispatch_log */ ?>
  <tr><td>전송 기록</td><td>외부 매체로 보낸 시각, 응답 코드, 소요 시간</td><td>3개월</td></tr>
  <?php /* create_sessions — ci_sessions */ ?>
  <tr><td>세션</td><td>세션 식별자, IP 주소</td><td>만료(2시간) 후 삭제</td></tr>
  <?php /* create_users_conversions · create_payments_coins */ ?>
  <tr><td>회원·전환·결제</td>
      <td>시연용 데이터만 있습니다. 실제 가입·결제 기능이 생기면 이 표를 먼저 고칩니다</td>
      <td>(해당 시) 5년 — 전자상거래법</td></tr>
</table>
</div>
<p>기간이 지난 정보는 매일 자동으로 삭제합니다.</p>

<h2 id="cookies">3. 쿠키</h2>
<div class="scroll">
<table>
  <tr><th>이름</th><th>누가</th><th>용도</th><th>기간</th></tr>
  <tr><td><code>ab_vid</code></td><td>이 사이트</td><td>같은 방문자인지 알아보기</td><td>1년</td></tr>
  <tr><td><code>tp_sess</code></td><td>이 사이트</td><td>세션 유지</td><td>2시간</td></tr>
  <?php /* config.php csrf_cookie_name · docker/openresty/lua/replica.lua — 09-15 응답 헤더에서 발견해 추가 */ ?>
  <tr><td><code>tp_csrf</code></td><td>이 사이트</td><td>위조 요청 방지 토큰</td><td>2시간</td></tr>
  <tr><td><code>ab_rdb</code></td><td>이 사이트</td><td>읽기용 데이터베이스 배정 (개인 식별 없음)</td><td>1일</td></tr>
  <tr><td><code>tp_ad_optout</code></td><td>이 사이트</td><td>맞춤형 광고 거부 설정 기억</td><td>1년</td></tr>
  <tr><td><code>tp_probe_vid</code></td><td>이 사이트</td><td>진단 페이지(<code>/diag</code>)의 쿠키 동작 시험</td><td>짧은 기간</td></tr>
  <tr><td><code>_ga</code>, <code>_ga_*</code></td><td>Google</td><td>이용 통계</td><td>최대 2년</td></tr>
  <tr><td><code>_fbp</code></td><td>Meta</td><td>광고 성과 측정 (픽셀이 켜져 있을 때)</td><td>90일</td></tr>
  <tr><td><code>_fbc</code></td><td>Meta</td><td>Meta 광고를 눌러 들어온 경우의 클릭 식별</td><td>90일</td></tr>
</table>
</div>
<p>브라우저 설정에서 쿠키를 막거나 지울 수 있습니다. 막으면 방문 경로 측정이 끊기지만 사이트를 보는 데는 지장이 없습니다.</p>

<h2 id="transfer">4. 외부(국외)로 보내는 정보</h2>
<div class="scroll">
<table>
  <tr><th>받는 곳</th><th>항목</th><th>언제·어떻게</th><th>목적</th><th>보유</th></tr>
  <?php /* partials/gtag · src/Channel/Ga4Channel */ ?>
  <tr><td>Google LLC<br>(미국)</td>
      <td>페이지 조회, <code>_ga</code> 식별자, 전환 종류·금액·통화·전환 번호, 시연용 회원 번호</td>
      <td>페이지를 열 때 브라우저가 전송 · 전환이 기록되면 서버가 전송 (TLS)</td>
      <td>이용 통계</td>
      <td><a href="https://policies.google.com/privacy">Google 정책</a>에 따름</td></tr>
  <?php /* partials/meta_pixel · src/Channel/MetaChannel */ ?>
  <tr><td>Meta Platforms, Inc.<br>(미국)</td>
      <td>페이지 조회(픽셀), 브라우저 정보(UA)·IP 주소 원문, 페이지 주소, <code>_fbp</code>·<code>_fbc</code>,
          <strong>해시한</strong> 회원 번호, 전환 종류·금액·통화·시각·전환 번호</td>
      <td>페이지를 열 때 브라우저가 전송(픽셀) · 결제·전환이 기록되면 서버가 전송 (TLS)</td>
      <td>광고 성과 측정, 맞춤형 광고</td>
      <td><a href="https://www.facebook.com/privacy/policy/">Meta 정책</a>에 따름</td></tr>
</table>
</div>

<h2 id="ads">5. 맞춤형 광고 거부</h2>
<div class="ads">
  지금 이 브라우저:
  <?php if ($opted_out): ?>
    <span class="state off">거부함</span>
    <p style="margin:.5rem 0 0">Meta 픽셀을 불러오지 않고, 결제·전환이 일어나도 브라우저 정보를 Meta 로 보내지 않습니다.</p>
    <a class="btn" href="/privacy/ads?off=0">거부 해제</a>
  <?php else: ?>
    <span class="state on">허용</span>
    <p style="margin:.5rem 0 0">
      <?= $pixel_on ? 'Meta 픽셀이 켜져 있고,' : 'Meta 픽셀은 현재 꺼져 있고,' ?>
      결제·전환 시 브라우저 정보를 Meta 로 보냅니다.
    </p>
    <a class="btn" href="/privacy/ads?off=1">맞춤형 광고 거부</a>
  <?php endif; ?>
  <?php if ($done === 'off'): ?><p class="done">거부했습니다. 이 사이트의 모든 하위 주소에 적용되고, 기존 Meta 쿠키도 지웠습니다.</p><?php endif; ?>
  <?php if ($done === 'on'): ?><p class="done">거부를 해제했습니다.</p><?php endif; ?>
</div>
<p>이 설정은 쿠키로 기억하므로, 브라우저나 기기를 바꾸면 다시 선택해야 합니다. 다른 방법도 있습니다.</p>
<ul>
  <li>Meta: <a href="https://www.facebook.com/adpreferences">광고 기본 설정</a></li>
  <li>Google Analytics: <a href="https://tools.google.com/dlpage/gaoptout">차단 브라우저 부가기능</a></li>
</ul>

<h2 id="safety">6. 어떻게 보호하나요</h2>
<ul>
  <li>모든 연결은 HTTPS 로만 받습니다</li>
  <li>방문 기록의 브라우저 정보·IP 는 해시로만 저장하고, 원문은 광고 전송용 기록에만 3개월 둡니다</li>
  <li>페이지 주소를 외부로 보낼 때 쿼리 문자열(이메일·토큰이 섞일 수 있는 부분)을 떼어 냅니다</li>
  <li>외부 서비스 인증 정보는 서버에만 두고 코드 저장소에 올리지 않습니다</li>
  <li>보관 기간이 지난 정보는 매일 자동 삭제합니다</li>
</ul>

<h2 id="rights">7. 요청하실 수 있는 것</h2>
<p>이 사이트가 가진 본인 정보의 열람·정정·삭제·처리 정지를 요청하실 수 있습니다.
   방문 기록은 쿠키로만 식별되므로, 요청하실 때 브라우저의 <code>ab_vid</code> 값을 함께 알려 주시면 찾을 수 있습니다.</p>

<h2 id="contact">8. 문의</h2>
<?php if ($contact !== ''): ?>
  <p>개인정보 관련 문의: <?= html_escape($contact) ?></p>
<?php else: ?>
  <p>운영자에게 <a href="<?= $repo ?>/issues">저장소 이슈</a>로 연락해 주세요.
     이슈는 공개되니 개인정보는 적지 마시고, 연락 방법만 남겨 주시면 따로 연락드립니다.</p>
<?php endif; ?>
<p>개인정보 침해 신고·상담: 개인정보침해신고센터 (국번 없이 118) · 개인정보분쟁조정위원회 (1833-6972)</p>

<h2 id="changes">9. 변경</h2>
<p>수집하는 정보나 보내는 곳이 바뀌면 이 페이지를 먼저 고치고 시행일을 바꿉니다.
   변경 이력은 <a href="<?= $repo ?>/commits/main/application/views/privacy/index.php">저장소 기록</a>에 남습니다.</p>

<footer>touchpoint</footer>
</div>
</body>
</html>
