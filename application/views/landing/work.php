<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** @var array $touchpoints first/last Touchpoint 또는 NULL */
$fields = array(
	'pid' => 'pid', 'subpid' => 'subpid', 'channel' => 'channel',
	'utmSource' => 'utm_source', 'utmMedium' => 'utm_medium',
	'utmCampaign' => 'utm_campaign', 'utmContent' => 'utm_content',
	'utmTerm' => 'utm_term', 'gclid' => 'gclid', 'fbclid' => 'fbclid',
);
?>
<!doctype html>
<html lang="ko">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>작품 <?= html_escape($work) ?> · touchpoint</title>
<style>
  body{font:14px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:2rem;
       background:#0f1115;color:#e6e8ec}
  main{max-width:46rem;margin:0 auto}
  h1{font-size:1.1rem;margin:0 0 .25rem}
  .sub{color:#8b93a1;margin:0 0 1.5rem}
  table{width:100%;border-collapse:collapse;margin-bottom:1.5rem}
  th,td{text-align:left;padding:.4rem .25rem;border-bottom:1px solid #232733;vertical-align:top}
  th{color:#8b93a1;font-weight:400;width:9rem}
  code{background:#1a1e27;padding:.1rem .35rem;border-radius:3px;word-break:break-all}
  .none{color:#5b6270}
  .tag{display:inline-block;padding:.05rem .4rem;border-radius:3px;font-size:.85em}
  .new{background:#1e3a2a;color:#7fd6a0} .kept{background:#2a2620;color:#d6b87f}
  .note{margin-top:1.5rem;padding:.9rem 1rem;background:#171b23;border-left:2px solid #3a4152;color:#a8b0bd}
</style>
<main>
  <h1>작품 <?= html_escape($work) ?></h1>
  <p class="sub">
    방문 <code><?= html_escape($visit_uid) ?></code>
    <span class="tag <?= $is_new ? 'new' : 'kept' ?>"><?= $is_new ? '새 방문' : '기존 방문' ?></span>
  </p>

  <table>
    <tr>
      <th>이번 요청</th>
      <td>
        <?= $result['is_direct'] ? '유입 파라미터 없음(직접 유입)' : '유입 파라미터 있음' ?> ·
        first <span class="tag <?= $result['first'] === 'created' ? 'new' : 'kept' ?>"><?= html_escape($result['first']) ?></span> ·
        last <span class="tag <?= $result['last'] === 'updated' ? 'new' : 'kept' ?>"><?= html_escape($result['last']) ?></span>
      </td>
    </tr>
    <tr><th>읽기 대상</th><td><code><?= html_escape($read_target) ?></code></td></tr>
    <tr><th>상관 ID</th><td><code><?= html_escape($trace_id) ?></code></td></tr>
  </table>

  <?php foreach (array('first' => '최초 유입', 'last' => '마지막 유입') as $pos => $label): ?>
    <h1><?= html_escape($label) ?></h1>
    <?php if ($touchpoints[$pos] === NULL): ?>
      <p class="sub none">아직 없습니다.</p>
    <?php else: ?>
      <table>
        <?php foreach ($fields as $prop => $shown): ?>
          <?php $v = $touchpoints[$pos]->{$prop}; ?>
          <tr>
            <th><?= html_escape($shown) ?></th>
            <td><?= $v === NULL ? '<span class="none">—</span>' : '<code>'.html_escape($v).'</code>' ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><th>기록 시각</th><td><code><?= html_escape($touchpoints[$pos]->occurredAt->format('c')) ?></code></td></tr>
      </table>
    <?php endif; ?>
  <?php endforeach; ?>

  <div class="note">
    <strong>확인할 것</strong><br>
    유입 파라미터를 붙여 <code>/go?work=<?= html_escape($work) ?>&amp;pid=google&amp;utm_source=google</code> 로 들어온 뒤,
    파라미터 없이 이 주소를 다시 열어 보세요.
    <strong>최초 유입은 그대로 있고 마지막 유입도 덮어쓰이지 않아야 합니다</strong> —
    직접 유입이 광고 성과를 지우면 안 되기 때문입니다.
  </div>
</main>
</html>
