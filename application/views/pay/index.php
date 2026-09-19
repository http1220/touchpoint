<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 시연 결제 화면. 입장권이 있어야 열린다 — 입장권은 안내 화면의 버튼으로 누구나 받는다 → controllers/Pay.php
 *
 * 두 길이다 (09-19).
 *
 *   ① 카드 없이 끝까지 — 가짜 PG. 버튼 → POST /purchase (pg 없음 = 스텁, demo- 키)
 *      → POST /pay/stub/confirm (서버가 확정 웹훅을 자기 /webhooks/pg 로 두 번) → /pay/result/{uid}
 *      **면접관이 카드를 넣지 않아도 "된다" 를 끝까지 본다.** 이 칸이 먼저인 이유다
 *   ② 이니시스 테스트 상점 · 카드. 버튼 → POST /purchase (pg=inicis) → POST /pay/inicis/start
 *      → INIStdPay.pay() → 이니시스 결제창 → POST /pay/inicis/return → /pay/result/{uid}
 *
 * 바닐라 JS 다 → ADR-009. 금액은 여기서 정하지 않는다 — 상품표의 값을 싣고, 서버가 대조한다.
 *
 * @var list<App\Payment\CoinProduct> $products
 * @var string      $user_uid  시연 회원 (.env PAY_DEMO_USER_UID)
 * @var string|null $inicis_js 결제창 스크립트. 모드가 틀렸으면 null
 * @var bool        $inicis_on 어댑터를 만들 수 있는가
 * @var string      $mode      test | live | ''
 * @var bool        $is_mobile 엣지 판별(AB_IS_MOBILE). 참이면 이니시스 PC 결제창을 그리지 않는다 → B10
 */
$ready  = $inicis_on && $inicis_js !== NULL && $user_uid !== '' && ! $is_mobile;
$cheap  = $products === array() ? NULL : $products[0];   // 카드 없는 길은 가장 싼 상품 하나로
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '시연 결제 · touchpoint')); ?>
<meta name="robots" content="noindex, nofollow">
<?php if ($ready): ?>
<script src="<?= html_escape($inicis_js) ?>" charset="UTF-8"></script>
<?php endif; ?>
</head>
<body>
<main class="stage">
  <div class="sheet layout-pay">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">시연 결제</span></p>
      <p class="eyebrow masthead__note">가짜 PG · 이니시스 <?= html_escape($mode === 'live' ? '운영' : '테스트') ?> · <a href="/pay/history">결제 이력</a></p>
    </header>

    <section class="panel" aria-labelledby="stub-title">
      <h1 id="stub-title">카드 없이 끝까지 — 가짜 PG</h1>
      <p>
        결제를 만들고, 가짜 결제대행사가 <strong>"결제됐다" 알림(웹훅)을 두 번</strong> 보냅니다.
        결과 화면에서 확정 한 번 · 무시 한 번 · 코인 지급 · 매체 전송을 장부 그대로 볼 수 있습니다.
        돈은 움직이지 않습니다.
      </p>
      <?php if ($user_uid === '' OR $cheap === NULL): ?>
        <p class="empty">지금은 해 볼 수 없습니다 — 시연 회원(PAY_DEMO_USER_UID)이 없습니다.</p>
      <?php else: ?>
        <p class="buttons">
          <button type="button" class="button" id="stub-run"
                  data-product="<?= html_escape($cheap->code) ?>"
                  data-amount="<?= (int) $cheap->amountMinor ?>" data-currency="<?= html_escape($cheap->currency) ?>">
            코인 <?= (int) $cheap->coins ?>개 · <?= number_format($cheap->amountMinor) ?>원 — 카드 없이 끝까지
          </button>
        </p>
        <p id="stub-status" role="status" aria-live="polite"></p>
      <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="pay-title">
      <h2 id="pay-title">이니시스 테스트 상점 · 카드</h2>
      <?php if ($mode !== 'live' && ! $is_mobile): ?>
      <p>
        이니시스 <strong>테스트 상점</strong>으로 연결됩니다. 테스트 상점도 <strong>카드 승인은 실제로 일어나고</strong>,
        이니시스가 당일 자정 전에 자동으로 취소합니다. 결제창을 열어 보고 카드를 넣지 않고 닫아도 됩니다.
      </p>
      <?php endif; ?>

      <?php if ($is_mobile): ?>
        <?php /* B10 을 뒤집었다(09-19 오후) — 처음엔 "모바일도 PC 결제창으로 시도, 안내 없음" 이었다.
                 모바일에서 누르면 이니시스가 "[INIStdPay / Dev. Error] … PC로 결제 진행을 부탁드립니다" 만 띄운다 */ ?>
        <p class="empty">
          카드 결제창은 <strong>PC 에서</strong> 열립니다. 이니시스 모바일 결제는 별도 규격(파라미터 · 금액 해시 · 복귀 흐름이 다르다)이라 붙이지 않았습니다.
          위의 <strong>카드 없이 끝까지</strong>는 여기서도 됩니다.
        </p>
      <?php elseif ( ! $ready): ?>
        <p class="empty">
          지금은 결제할 수 없습니다 —
          <?= $user_uid === '' ? '시연 회원(PAY_DEMO_USER_UID)이 없습니다.' : '이니시스 설정이 없거나 모드와 상점이 맞지 않습니다.' ?>
        </p>
      <?php else: ?>
        <div class="buttons">
          <?php foreach ($products as $p): ?>
            <button type="button" class="button" data-inicis
                    data-product="<?= html_escape($p->code) ?>"
                    data-amount="<?= (int) $p->amountMinor ?>" data-currency="<?= html_escape($p->currency) ?>">
              코인 <?= (int) $p->coins ?>개 · <?= number_format($p->amountMinor) ?>원
            </button>
          <?php endforeach; ?>
        </div>
        <p id="pay-status" role="status" aria-live="polite"></p>
      <?php endif; ?>
    </section>

    <!-- 서버가 서명한 필드가 여기 채워진다. INIStdPay.pay() 가 이 폼을 읽는다 -->
    <form id="inicis-form" method="post" accept-charset="UTF-8" hidden></form>
  </div>
</main>

<?php if ($user_uid !== ''): ?>
<script>
(function () {
  var USER = <?= json_encode($user_uid) ?>;

  async function post(url, body) {
    var res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin'
    });
    var json = {};
    try { json = await res.json(); } catch (e) { /* 본문 없음 */ }
    if (!res.ok) { throw new Error((json && json.detail) || ('HTTP ' + res.status)); }
    return json;
  }

  // 키는 클릭마다 새로 만든다. 두 번 누름은 버튼 비활성이 막는다 —
  // 그래도 행이 둘 생기면 확정된 쪽만 장부에 오르고 나머지는 created 로 남아 오래된 결제 보고에 잡힌다
  function purchase(btn, extra) {
    return post('/purchase', Object.assign({
      product: btn.dataset.product,
      amount_minor: Number(btn.dataset.amount),
      currency: btn.dataset.currency,
      user_uid: USER
    }, extra));
  }

  // ① 카드 없이 끝까지. pg 를 싣지 않으면 스텁이다. demo- 키만 확정 엔드포인트가 받는다 → StubWebhook::demoRejects
  var stub = document.getElementById('stub-run');
  if (stub) {
    var stubStatus = document.getElementById('stub-status');
    stub.addEventListener('click', async function () {
      stub.disabled = true;
      stubStatus.textContent = '결제를 만드는 중…';
      try {
        var p = await purchase(stub, { idempotency_key: 'demo-' + crypto.randomUUID() });
        stubStatus.textContent = '가짜 PG 가 "결제됐다" 알림을 두 번 보내는 중…';
        var c = await post('/pay/stub/confirm', { payment_uid: p.payment_uid });
        location.href = c.result_url;
      } catch (e) {
        stubStatus.textContent = '끝까지 가지 못했습니다: ' + e.message;
        stub.disabled = false;
      }
    });
  }

  // ② 이니시스
  var payStatus = document.getElementById('pay-status');
  document.querySelectorAll('[data-inicis]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      payStatus.textContent = '결제를 만드는 중…';
      try {
        var p = await purchase(btn, { idempotency_key: 'pay-' + crypto.randomUUID(), pg: 'inicis' });
        var start = await post('/pay/inicis/start', { payment_uid: p.payment_uid });

        var form = document.getElementById('inicis-form');
        form.replaceChildren();
        Object.keys(start.fields).forEach(function (name) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = start.fields[name];
          form.appendChild(input);
        });

        payStatus.textContent = '결제창을 엽니다.';
        INIStdPay.pay('inicis-form');
      } catch (e) {
        payStatus.textContent = '결제를 시작하지 못했습니다: ' + e.message;
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
