<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 결제 결과. 장부(payments · payment_events)를 **그대로** 보여 준다.
 *
 * 요약 문장을 만들지 않고 이벤트 줄을 보인다 — "승인됐는데 장부에 못 올려
 * 망취소했다" 같은 경우가 한 줄 요약에서는 사라진다.
 *
 * @var array|null  $payment Payment_model::findByUid
 * @var list<array> $events  Payment_model::events
 * @var array       $refs    PG 번호
 * @var array|null  $effects Payment_model::effects — 코인과 매체 전송
 * @var string|null $message 결제를 찾지 못했을 때 등
 */
$labels = array(
	'created'  => '결제 생성 — 승인 없음 · 청구되지 않음',
	'pending'  => '보류',
	'captured' => '승인 — 코인 지급',
	'failed'   => '실패',
	'refunded' => '환불',
);
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '결제 결과 · touchpoint')); ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<main class="stage">
  <div class="sheet layout-pay">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">결제 결과</span></p>
    </header>

    <section class="panel" aria-labelledby="result-title">
      <h1 id="result-title">
        <?= $payment === NULL ? '결제를 처리하지 않았습니다' : html_escape($labels[$payment['status']] ?? $payment['status']) ?>
      </h1>

      <?php if ($message !== NULL): ?>
        <p><?= html_escape($message) ?></p>
      <?php endif; ?>

      <?php if ($payment !== NULL): ?>
        <dl class="kv">
          <dt>결제</dt><dd><code><?= html_escape($payment['uid_hex']) ?></code></dd>
          <dt>PG</dt><dd><?= html_escape($payment['pg']) ?><?php foreach ($refs as $type => $value): ?> · <?= html_escape($type) ?> <code><?= html_escape($value) ?></code><?php endforeach; ?></dd>
          <dt>금액</dt><dd><?= number_format($payment['amount_minor']) ?> <?= html_escape($payment['currency']) ?></dd>
          <dt>승인 시각</dt><dd><?= $payment['captured_at'] === NULL ? '—' : html_escape($payment['captured_at']).' UTC' ?></dd>
          <?php if ($effects !== NULL): ?>
            <?php /* 장부 한 줄(captured) 뒤에 같은 트랜잭션으로 적힌 것들 → Payment_model::effects */ ?>
            <dt>코인</dt><dd><?= $effects['lots'] === 0 ? '지급 없음' : number_format($effects['coins']).'개 지급 (지급 건 '.(int) $effects['lots'].')' ?></dd>
            <dt>매체 전송</dt>
            <dd>
              <?php if ($effects['conversion_uid'] === NULL): ?>
                구매 전환 없음
              <?php else: ?>
                구매 전환 1건 →
                <?php if ($effects['outbox'] === array()): ?>보낼 매체 없음<?php endif; ?>
                <?php foreach ($effects['outbox'] as $channel => $state): ?>
                  <?= html_escape($channel) ?> <span class="badge<?= $state === 'sent' ? ' badge--success' : '' ?>"><?= html_escape($state) ?></span>
                <?php endforeach; ?>
                <?php if (array_diff($effects['outbox'], array('sent')) !== array()): ?>
                  <span class="muted">— pending · sending 은 워커가 처리하는 중입니다. 새로고침하면 바뀝니다</span>
                <?php endif; ?>
              <?php endif; ?>
            </dd>
          <?php endif; ?>
        </dl>

        <h2>장부 이벤트</h2>
        <?php if ($payment['pg'] === 'stub' && str_starts_with($payment['idempotency_key'], 'demo-')): ?>
          <?php
            // 고정 문장으로 "한 번만 나갔다" 고 말하지 않는다 — 장부에서 센 값을 그대로 보인다.
            // 두 번 나갔다면 이 줄이 그렇게 말해야 한다
            $captures = count(array_filter($events, static function ($e) { return $e['to'] === 'captured' && $e['from'] !== 'captured'; }));
            $ignored  = count(array_filter($events, static function ($e) { return $e['from'] !== NULL && $e['from'] === $e['to']; }));
          ?>
          <p class="caption"><strong>가짜 PG 가 같은 "결제됐다" 알림을 두 번 보냈습니다.</strong>
            장부에서 센 결과 — 확정 전이 <strong><?= (int) $captures ?>번</strong> · 무시 <strong><?= (int) $ignored ?>번</strong> ·
            코인 지급 건 <strong><?= $effects === NULL ? 0 : (int) $effects['lots'] ?></strong>.
            무시된 알림은 <code>captured → captured</code> 처럼 from 과 to 가 같은 줄로 남습니다.</p>
        <?php endif; ?>
        <p class="caption">from 과 to 가 같은 줄은 전이가 아니라 <strong>무시된 이벤트</strong>입니다 — 중복·역전·장부에 올리지 않은 승인.</p>
        <div class="table-scroll" role="region" aria-label="결제 이벤트" tabindex="0">
          <table class="data">
            <thead><tr><th scope="col">시각(UTC)</th><th scope="col">from</th><th scope="col">to</th><th scope="col">문</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e): ?>
              <tr>
                <td class="nowrap"><?= html_escape($e['created_at']) ?></td>
                <td><?= $e['from'] === NULL ? '—' : html_escape($e['from']) ?></td>
                <td><?= html_escape($e['to']) ?></td>
                <td><?= html_escape($e['source']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <p><a class="button" href="/pay">결제 화면으로</a></p>
    </section>
  </div>
</main>
</body>
</html>
