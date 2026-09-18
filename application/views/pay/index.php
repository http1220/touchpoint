<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 시연 결제 화면 (이니시스 · 카드). 시연 토큰이 있어야 열린다 → controllers/Pay.php
 *
 * 흐름: 버튼 → POST /purchase (pg=inicis) → POST /pay/inicis/start → INIStdPay.pay()
 * → 이니시스 결제창 → POST /pay/inicis/return → /pay/result/{uid}
 *
 * 바닐라 JS 다 → ADR-009. 결제창 필드는 **서버가 서명해 준 것을 그대로** 폼에 넣는다 —
 * 여기서 금액을 정하지 않는다.
 *
 * @var list<App\Payment\CoinProduct> $products
 * @var string      $user_uid  시연 회원 (.env PAY_DEMO_USER_UID)
 * @var string|null $inicis_js 결제창 스크립트. 모드가 틀렸으면 null
 * @var bool        $inicis_on 어댑터를 만들 수 있는가
 * @var string      $mode      test | live | ''
 */
$ready = $inicis_on && $inicis_js !== NULL && $user_uid !== '';
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
      <p class="eyebrow masthead__note">이니시스 <?= html_escape($mode === 'live' ? '운영' : '테스트') ?> · 카드</p>
    </header>

    <section class="panel" aria-labelledby="pay-title">
      <h1 id="pay-title">코인 결제</h1>
      <?php if ($mode !== 'live'): ?>
      <p>
        이니시스 <strong>테스트 상점</strong>으로 연결됩니다. 테스트 상점도 <strong>카드 승인은 실제로 일어나고</strong>,
        이니시스가 당일 자정 전에 자동으로 취소합니다.
      </p>
      <?php endif; ?>
      <?php /* 모바일 안내는 두지 않는다 — PC 결제창으로 시도하기로 했다 → plan-multi-pg.md 6장 B10 */ ?>

      <?php if ( ! $ready): ?>
        <p class="empty">
          지금은 결제할 수 없습니다 —
          <?= $user_uid === '' ? '시연 회원(PAY_DEMO_USER_UID)이 없습니다.' : '이니시스 설정이 없거나 모드와 상점이 맞지 않습니다.' ?>
        </p>
      <?php else: ?>
        <div class="buttons">
          <?php foreach ($products as $p): ?>
            <button type="button" class="button" data-product="<?= html_escape($p->code) ?>"
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

<?php if ($ready): ?>
<script>
(function () {
  var USER = <?= json_encode($user_uid) ?>;
  var status = document.getElementById('pay-status');

  function say(msg) { status.textContent = msg; }

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

  document.querySelectorAll('[data-product]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      say('결제를 만드는 중…');
      try {
        // 키는 클릭마다 새로 만든다. 두 번 누름은 버튼 비활성이 막는다(위 disabled) —
        // 그래도 행이 둘 생기면 결제창을 연 쪽만 승인되고 나머지는 created 로 남아 오래된 결제 보고에 잡힌다
        var purchase = await post('/purchase', {
          product: btn.dataset.product,
          amount_minor: Number(btn.dataset.amount),
          currency: btn.dataset.currency,
          idempotency_key: 'pay-' + crypto.randomUUID(),
          user_uid: USER,
          pg: 'inicis'
        });

        var start = await post('/pay/inicis/start', { payment_uid: purchase.payment_uid });

        var form = document.getElementById('inicis-form');
        form.replaceChildren();
        Object.keys(start.fields).forEach(function (name) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = start.fields[name];
          form.appendChild(input);
        });

        say('결제창을 엽니다.');
        INIStdPay.pay('inicis-form');
      } catch (e) {
        say('결제를 시작하지 못했습니다: ' + e.message);
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
