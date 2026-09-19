<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 개인정보처리방침. 서버 렌더 · JS 없음.
 *
 * **내용은 코드에서 옮겼다** — 컨트롤러 주석 참조. 코드가 수집하는 것을
 * 바꾸면 여기도 바꾼다. 표의 각 줄 옆 주석이 근거 위치다.
 *
 * 시트로 옮기면서 **문구와 절 번호, 앵커 id 는 건드리지 않았다.**
 * 다른 화면이 #ads 로 들어오고, 시행일이 붙은 문서다.
 *
 * @var string      $shop
 * @var string      $contact
 * @var bool        $opted_out
 * @var bool        $pixel_on
 * @var string|null $done
 */
$repo = 'https://github.com/http1220/touchpoint';

$sections = array(
	'purpose'  => '1. 무엇을 위해 처리하나요',
	'items'    => '2. 어떤 정보를, 얼마나 보관하나요',
	'cookies'  => '3. 쿠키',
	'transfer' => '4. 외부(국외)로 보내는 정보',
	'ads'      => '5. 맞춤형 광고 거부',
	'safety'   => '6. 어떻게 보호하나요',
	'rights'   => '7. 요청하실 수 있는 것',
	'contact'  => '8. 문의',
	'changes'  => '9. 변경',
);
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '개인정보처리방침 · touchpoint')); ?>
</head>
<body>
<main class="stage">

  <!-- ── 시트 1 · 머리와 요약 ─────────────────────── -->
  <div class="sheet layout-privacy-1">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">웹툰</span></p>
      <p class="eyebrow masthead__note">시행일 2026년 9월 19일</p>
    </header>

    <section class="panel panel--bottom privacy-title" aria-labelledby="page-title">
      <h1 id="page-title">개인정보처리방침</h1>
      <p>
        이 사이트는 <strong>개인이 운영하는 채용 포트폴리오 시연 사이트</strong>입니다.
        광고 유입부터 전환까지를 추적하는 구조를 보여 주기 위해 만들었고,
        <strong>실제 회원가입은 없습니다.</strong>
        <?php /* 09-19: 이니시스 테스트 상점 연결(controllers/Pay.php). 전에는 "결제(청구)는 없습니다" 였다 */ ?>
        결제는 결제대행사(PG)의 <strong>테스트 환경</strong>에만 연결되어 있고, <a href="<?= html_escape(tp_host_url('root', '/tour')) ?>#pay">시연 안내</a>의 버튼으로 누구나 해 볼 수 있습니다.
        테스트 환경이라도 <strong>카드 승인은 실제로 일어나고</strong>, 결제대행사가 당일 자정 전에 자동으로 취소합니다.
        아래 내용은 이 사이트의 코드가 실제로 하는 일을 그대로 적은 것입니다
        (<a href="<?= $repo ?>">소스 공개</a>).
      </p>
    </section>

    <nav class="panel privacy-toc" aria-labelledby="toc-title">
      <h2 id="toc-title">차례</h2>
      <ol class="rules">
        <?php foreach ($sections as $id => $label): ?>
          <li><a href="#<?= $id ?>"><?= html_escape(preg_replace('/\A\d+\.\s*/', '', $label)) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </nav>
  </div>

  <!-- ── 시트 2 · 1 · 2 ───────────────────────────── -->
  <div class="sheet layout-privacy-doc">
    <section class="panel" aria-labelledby="purpose">
      <h2 id="purpose">1. 무엇을 위해 처리하나요</h2>
      <ul>
        <li>광고·링크를 통해 들어온 방문이 어떤 경로로 이어졌는지 측정</li>
        <li>이용 통계 분석 (Google Analytics)</li>
        <li>광고 성과 측정과 맞춤형 광고 (Meta) — <a href="#ads">거부할 수 있습니다</a></li>
        <li>서비스 안정 운영 (세션 유지, 장애 분석)</li>
      </ul>
    </section>

    <section class="panel" aria-labelledby="items">
      <h2 id="items">2. 어떤 정보를, 얼마나 보관하나요</h2>
      <div class="table-scroll" tabindex="0" role="region" aria-label="수집 항목과 보관 기간 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">구분</th><th scope="col">항목</th><th scope="col">보관 기간</th></tr></thead>
          <tbody>
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
            <?php /* application/core/MY_Log · src/Support/LogFile — 09-15 추가 */ ?>
            <tr><td>서버 로그</td><td>처리 결과 메시지, 요청 상관 ID, 방문·결제·전환 번호, 요청을 보낸 사이트 주소</td><td>3개월</td></tr>
            <?php /* create_sessions — ci_sessions */ ?>
            <tr><td>세션</td><td>세션 식별자, IP 주소</td><td>만료(2시간) 후 삭제</td></tr>
            <?php /* create_users_conversions · create_payments_coins */ ?>
            <tr><td>회원·전환</td>
                <td>시연용 데이터만 있습니다. 실제 가입 기능이 생기면 이 표를 먼저 고칩니다</td>
                <td>(해당 시) 5년 — 전자상거래법</td></tr>
            <?php /* create_payment_pg_refs · Gateway/InicisGateway STORED_FIELDS · PayloadRedactor — 09-19 */ ?>
            <tr><td>결제 기록</td>
                <td>결제 번호, 금액·통화, 상태가 바뀐 시각, 결제대행사 거래 번호, 승인 결과 일부(결과 코드·승인 일시·승인 번호·카드사 코드·할부 개월)<br>
                    — <strong>카드번호와 구매자 이름·연락처는 저장하지 않습니다.</strong> 결제대행사 응답 원문은 해시값만 남깁니다</td>
                <td>5년 — 전자상거래법</td></tr>
          </tbody>
        </table>
      </div>
      <p>기간이 지난 정보는 매일 자동으로 삭제합니다.</p>
    </section>
  </div>

  <!-- ── 시트 3 · 3 · 4 ───────────────────────────── -->
  <div class="sheet layout-privacy-doc">
    <section class="panel" aria-labelledby="cookies">
      <h2 id="cookies">3. 쿠키</h2>
      <div class="table-scroll" tabindex="0" role="region" aria-label="쿠키 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">이름</th><th scope="col">누가</th><th scope="col">용도</th><th scope="col">기간</th></tr></thead>
          <tbody>
            <tr><td><code>ab_vid</code></td><td>이 사이트</td><td>같은 방문자인지 알아보기</td><td>1년</td></tr>
            <tr><td><code>tp_sess</code></td><td>이 사이트</td><td>세션 유지</td><td>2시간</td></tr>
            <?php /* config.php csrf_cookie_name · docker/openresty/lua/replica.lua — 09-15 응답 헤더에서 발견해 추가 */ ?>
            <tr><td><code>tp_csrf</code></td><td>이 사이트</td><td>위조 요청 방지 토큰</td><td>2시간</td></tr>
            <tr><td><code>ab_rdb</code></td><td>이 사이트</td><td>읽기용 데이터베이스 배정 (개인 식별 없음)</td><td>1일</td></tr>
            <tr><td><code>tp_ad_optout</code></td><td>이 사이트</td><td>맞춤형 광고 거부 설정 기억</td><td>1년</td></tr>
            <?php /* src/Payment/PayAccess · controllers/Pay::grant — 09-19 */ ?>
            <tr><td><code>tp_pay</code></td><td>이 사이트</td><td>시연 결제 입장권 — 버튼을 누른 브라우저에 주는 서명된 임의 값 (개인 식별 없음)</td><td>1일</td></tr>
            <tr><td><code>tp_probe_vid</code></td><td>이 사이트</td><td>진단 페이지(<code>/diag</code>)의 쿠키 동작 시험</td><td>짧은 기간</td></tr>
            <tr><td><code>_ga</code>, <code>_ga_*</code></td><td>Google</td><td>이용 통계</td><td>최대 2년</td></tr>
            <tr><td><code>_fbp</code></td><td>Meta</td><td>광고 성과 측정 (픽셀이 켜져 있을 때)</td><td>90일</td></tr>
            <tr><td><code>_fbc</code></td><td>Meta</td><td>Meta 광고를 눌러 들어온 경우의 클릭 식별</td><td>90일</td></tr>
          </tbody>
        </table>
      </div>
      <p>브라우저 설정에서 쿠키를 막거나 지울 수 있습니다. 막으면 방문 경로 측정이 끊기지만 사이트를 보는 데는 지장이 없습니다.</p>
    </section>

    <section class="panel" aria-labelledby="transfer">
      <h2 id="transfer">4. 외부(국외)로 보내는 정보</h2>
      <div class="table-scroll" tabindex="0" role="region" aria-label="국외 이전 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">받는 곳</th><th scope="col">항목</th><th scope="col">언제·어떻게</th><th scope="col">목적</th><th scope="col">보유</th></tr></thead>
          <tbody>
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
            <?php /* controllers/Pay::inicis_start · Gateway/InicisGateway::checkout — 09-19 */ ?>
            <tr><td>(주)케이지이니시스<br>(국내 결제대행)</td>
                <td>주문 번호, 금액, 상품명, 구매자 이름·휴대폰·이메일 — <strong>시연용 고정값이며 실제 개인정보가 아닙니다</strong><br>
                    카드 정보는 이니시스 결제창에 직접 입력하며 이 사이트를 거치지 않습니다</td>
                <td>시연 결제 화면에서 결제할 때 브라우저가 결제창으로 전송 · 승인·취소는 서버가 요청 (TLS)</td>
                <td>카드 결제 처리 (테스트 환경)</td>
                <td><a href="https://www.inicis.com/">KG이니시스 정책</a>에 따름</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- ── 시트 4 · 5 · 6 ───────────────────────────── -->
  <div class="sheet layout-privacy-doc">
    <section class="panel ad-optout" aria-labelledby="ads">
      <h2 id="ads">5. 맞춤형 광고 거부</h2>
      <p class="facts-line">
        지금 이 브라우저:
        <?php if ($opted_out): ?>
          <span class="badge badge--strong">거부함</span>
        <?php else: ?>
          <span class="badge">허용</span>
        <?php endif; ?>
      </p>
      <?php if ($opted_out): ?>
        <p>Meta 픽셀을 불러오지 않고, 결제·전환이 일어나도 브라우저 정보를 Meta 로 보내지 않습니다.</p>
        <p class="buttons"><a class="button" href="/privacy/ads?off=0">거부 해제</a></p>
      <?php else: ?>
        <p>
          <?= $pixel_on ? 'Meta 픽셀이 켜져 있고,' : 'Meta 픽셀은 현재 꺼져 있고,' ?>
          결제·전환 시 브라우저 정보를 Meta 로 보냅니다.
        </p>
        <p class="buttons"><a class="button" href="/privacy/ads?off=1">맞춤형 광고 거부</a></p>
      <?php endif; ?>
      <?php if ($done === 'off'): ?><p class="caption">거부했습니다. 이 사이트의 모든 하위 주소에 적용되고, 기존 Meta 쿠키도 지웠습니다.</p><?php endif; ?>
      <?php if ($done === 'on'): ?><p class="caption">거부를 해제했습니다.</p><?php endif; ?>
      <p>이 설정은 쿠키로 기억하므로, 브라우저나 기기를 바꾸면 다시 선택해야 합니다. 다른 방법도 있습니다.</p>
      <ul>
        <li>Meta: <a href="https://www.facebook.com/adpreferences">광고 기본 설정</a> — Meta 계정 로그인이 필요합니다</li>
        <li>Google Analytics: <a href="https://tools.google.com/dlpage/gaoptout">차단 브라우저 부가기능</a></li>
      </ul>
    </section>

    <section class="panel" aria-labelledby="safety">
      <h2 id="safety">6. 어떻게 보호하나요</h2>
      <ul>
        <li>모든 연결은 HTTPS 로만 받습니다</li>
        <li>방문 기록의 브라우저 정보·IP 는 해시로만 저장하고, 원문은 광고 전송용 기록에만 3개월 둡니다</li>
        <li>페이지 주소를 외부로 보낼 때 쿼리 문자열(이메일·토큰이 섞일 수 있는 부분)을 떼어 냅니다</li>
        <li>외부 서비스 인증 정보는 서버에만 두고 코드 저장소에 올리지 않습니다</li>
        <li>보관 기간이 지난 정보는 매일 자동 삭제합니다</li>
      </ul>
    </section>
  </div>

  <!-- ── 시트 5 · 7 · 8 · 9 ───────────────────────── -->
  <div class="sheet layout-privacy-doc">
    <section class="panel" aria-labelledby="rights">
      <h2 id="rights">7. 요청하실 수 있는 것</h2>
      <p>이 사이트가 가진 본인 정보의 열람·정정·삭제·처리 정지를 요청하실 수 있습니다.
         방문 기록은 쿠키로만 식별되므로, 요청하실 때 브라우저의 <code>ab_vid</code> 값을 함께 알려 주시면 찾을 수 있습니다.</p>
    </section>

    <section class="panel" aria-labelledby="contact">
      <h2 id="contact">8. 문의</h2>
      <?php if ($contact !== ''): ?>
        <p>개인정보 관련 문의: <?= html_escape($contact) ?></p>
      <?php else: ?>
        <p>운영자에게 <a href="<?= $repo ?>/issues">저장소 이슈</a>로 연락해 주세요.
           이슈는 공개되니 개인정보는 적지 마시고, 연락 방법만 남겨 주시면 따로 연락드립니다.</p>
      <?php endif; ?>
      <p>개인정보 침해 신고·상담: 개인정보침해신고센터 (국번 없이 118) · 개인정보분쟁조정위원회 (1833-6972)</p>
    </section>

    <section class="panel" aria-labelledby="changes">
      <h2 id="changes">9. 변경</h2>
      <p>수집하는 정보나 보내는 곳이 바뀌면 이 페이지를 먼저 고치고 시행일을 바꿉니다.
         변경 이력은 <a href="<?= $repo ?>/commits/main/application/views/privacy/index.php">저장소 기록</a>에 남습니다.</p>
    </section>
  </div>

</main>

<?php $this->load->view('partials/footer', array('hide_privacy' => TRUE)); ?>
</body>
</html>
