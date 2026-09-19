<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Payment\PaymentOrigin;

/**
 * 결제 이력 — 입장권 없이 누구나 연다 → controllers/Pay::history
 *
 * 위는 이 브라우저에서 만든 결제(상세 링크), 아래는 최근 결제 전부(uid 앞 12자리만).
 * 스모크·측정·면접 대본으로 남은 행도 지우지 않고 출처를 단다 — 운영에서 무엇을
 * 시험했는지가 그대로 보이는 편이 낫다(사용자 결정, 09-20).
 *
 * 흐름 칸은 장부의 **전이만** 잇는다(from ≠ to). 무시된 이벤트는 따로 센다 →
 * Payment_model 클래스 주석의 읽는 규칙
 *
 * @var list<array>          $mine       Payment_model::history — 이 브라우저의 결제
 * @var array<string, int>   $mine_set   uid => 순번. 아래 표에서 "내 결제" 를 가른다
 * @var list<array>          $recent     Payment_model::history — 최근 전부
 * @var array<string, array<string, int>> $counts PG → 상태 → 결제 수
 * @var array<string, string> $excluded  대사에서 뺀 결제 → 사유
 * @var bool                 $has_access 입장권이 있는가 (상세 화면에 필요하다)
 */
$labels = array(
	'created'    => '생성 — 승인 없음',
	'pending'    => '보류',
	'authorized' => '승인 대기',
	'captured'   => '승인',
	'failed'     => '실패',
	'refunded'   => '환불',
);

$kst = new DateTimeZone('Asia/Seoul');
$at  = static function ($utc) use ($kst) {
	return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($kst)->format('m-d H:i');
};

$coins = static function (array $p) {
	if ($p['lots'] === 0)
	{
		return '—';
	}

	// 지급 건이 둘 이상이면 그대로 드러낸다 — D-3 대조군이 코인을 두 번 준 결제다
	return number_format($p['coins']).'개'.($p['lots'] > 1 ? ' · 지급 '.$p['lots'].'건' : '').($p['revoked'] > 0 ? ' · 회수' : '');
};

// 상태마다 <code> 하나 — 줄은 화살표에서만 바뀐다. 한 덩어리로 두면 폰에서 글자마다 접혔다(09-20, 390 실측)
$flow = static function (array $p) {
	$codes = array_map(static function ($s) { return '<code>'.html_escape($s).'</code>'; }, $p['path']);

	return implode(' → ', $codes).($p['ignored'] > 0 ? ' · 무시 '.(int) $p['ignored'] : '');
};

$byStatus = array();

foreach ($counts as $statuses)
{
	foreach ($statuses as $status => $n)
	{
		$byStatus[$status] = ($byStatus[$status] ?? 0) + $n;
	}
}

$total = array_sum($byStatus);

// 이니시스(실PG)로 승인까지 간 결제. 고정 문장으로 "없다" 고 쓰지 않는다 — 장부에서 센다
$inicisCaptured = ($counts['inicis']['captured'] ?? 0) + ($counts['inicis']['refunded'] ?? 0);
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '결제 이력 · touchpoint')); ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<main class="stage">
  <div class="sheet layout-pay">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => NULL)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">결제 이력</span></p>
    </header>

    <section class="panel" aria-labelledby="history-title">
      <h1 id="history-title">결제 이력</h1>
      <p>
        이 서버의 결제 <strong><?= number_format($total) ?>건</strong>
        <?php foreach ($labels as $status => $label): ?>
          <?php if ( ! empty($byStatus[$status])): ?> · <?= html_escape($label) ?> <strong><?= (int) $byStatus[$status] ?></strong><?php endif; ?>
        <?php endforeach; ?>
      </p>
      <p class="caption">"생성 — 승인 없음" 은 결제창을 닫았거나 확정 전에 멈춘 결제입니다. 청구되지 않았습니다.
        이니시스(테스트 상점 · 실제 카드)로 승인까지 간 결제는 <strong><?= (int) $inicisCaptured ?>건</strong>이고, 나머지 승인은 가짜 PG(stub)입니다.</p>

      <h2>이 브라우저에서 만든 결제</h2>
      <?php if ($mine === array()): ?>
        <p class="empty">아직 없습니다. <a href="/pay">결제 화면</a>에서 "카드 없이 끝까지" 를 누르면 여기에 나타납니다.</p>
      <?php else: ?>
        <?php if ( ! $has_access): ?>
          <p class="caption">상세 화면은 입장권이 필요합니다(1일). <a href="<?= html_escape(tp_host_url('root', '/tour#pay')) ?>">안내 화면</a>에서 다시 받을 수 있습니다.</p>
        <?php endif; ?>
        <div class="table-scroll" role="region" aria-label="이 브라우저에서 만든 결제" tabindex="0">
          <table class="data data--history">
            <thead><tr><th scope="col">시각(KST)</th><th scope="col">출처</th><th scope="col">금액</th><th scope="col">상태</th><th scope="col">장부 흐름</th><th scope="col">코인</th><th scope="col">상세</th></tr></thead>
            <tbody>
            <?php foreach ($mine as $p): ?>
              <tr>
                <td class="nowrap"><?= html_escape($at($p['created_at'])) ?></td>
                <td><?= html_escape(PaymentOrigin::label($p['idempotency_key'])) ?> <span class="muted">(<?= html_escape($p['pg']) ?>)</span></td>
                <td class="nowrap"><?= number_format($p['amount_minor']) ?> <?= html_escape($p['currency']) ?></td>
                <td><span class="badge<?= $p['status'] === 'captured' ? ' badge--success' : '' ?>"><?= html_escape($labels[$p['status']] ?? $p['status']) ?></span></td>
                <td class="flow"><?= $flow($p) ?></td>
                <td class="nowrap"><?= html_escape($coins($p)) ?></td>
                <td><a href="/pay/result/<?= html_escape($p['uid_hex']) ?>">장부 보기</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <h2>최근 결제 전부 <span class="muted">— <?= count($recent) ?>건<?= $total > count($recent) ? ' / '.number_format($total) : '' ?></span></h2>
      <p class="caption">
        다른 사람의 결제는 번호 앞 12자리(만든 시각)만 보이고 상세 링크가 없습니다.
        확인·측정용으로 만든 결제도 지우지 않았습니다 — <strong>출처는 결제를 만들 때 보낸 키의 모양으로 가른 자기 신고</strong>입니다.
      </p>
      <div class="table-scroll" role="region" aria-label="최근 결제 전부" tabindex="0">
        <table class="data data--history">
          <thead><tr><th scope="col">시각(KST)</th><th scope="col">결제</th><th scope="col">출처</th><th scope="col">금액</th><th scope="col">상태</th><th scope="col">장부 흐름</th><th scope="col">코인</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $p): ?>
            <?php $isMine = isset($mine_set[$p['uid_hex']]); ?>
            <tr>
              <td class="nowrap"><?= html_escape($at($p['created_at'])) ?></td>
              <td class="nowrap">
                <?php if ($isMine): ?>
                  <a href="/pay/result/<?= html_escape($p['uid_hex']) ?>"><code><?= html_escape(substr($p['uid_hex'], 0, 12)) ?>…</code></a> <span class="badge">내 결제</span>
                <?php else: ?>
                  <code><?= html_escape(substr($p['uid_hex'], 0, 12)) ?>…</code>
                <?php endif; ?>
              </td>
              <td>
                <?= html_escape(PaymentOrigin::label($p['idempotency_key'])) ?> <span class="muted">(<?= html_escape($p['pg']) ?>)</span>
                <?php if (isset($excluded[$p['uid_hex']])): ?><br><span class="muted"><?= html_escape($excluded[$p['uid_hex']]) ?></span><?php endif; ?>
              </td>
              <td class="nowrap"><?= number_format($p['amount_minor']) ?> <?= html_escape($p['currency']) ?></td>
              <td><span class="badge<?= $p['status'] === 'captured' ? ' badge--success' : '' ?>"><?= html_escape($labels[$p['status']] ?? $p['status']) ?></span></td>
              <td class="flow"><?= $flow($p) ?></td>
              <td class="nowrap"><?= html_escape($coins($p)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="buttons"><a class="button" href="/pay">결제 해 보기</a> <a href="<?= html_escape(tp_host_url('root', '/tour#pay')) ?>">안내 화면으로</a></p>
    </section>
  </div>
</main>
</body>
</html>
