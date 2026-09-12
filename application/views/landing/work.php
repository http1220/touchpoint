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
<?php $this->load->view('partials/gtag'); ?>
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
  button{font:inherit;padding:.35rem .7rem;margin:.15rem .2rem .15rem 0;border:1px solid #3a4152;
         border-radius:4px;background:#1a1e27;color:#e6e8ec;cursor:pointer}
  button:hover{background:#232733}
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

  <h1>수집 이벤트</h1>
  <p class="sub">
    복제본 <code><?= html_escape($read_target) ?></code> 에서 읽었습니다.
    방금 보낸 건이 안 보이면 <strong>복제 지연</strong>입니다 — 새로고침해 보세요.
  </p>

  <?php if ($events === array()): ?>
    <p class="sub none">아직 없습니다.</p>
  <?php else: ?>
    <table>
      <tr><th>이벤트</th><th>전송</th><th>오리진</th><th>발생(클라)</th><th>수신(서버)</th></tr>
      <?php foreach ($events as $e): ?>
        <tr>
          <td><code><?= html_escape($e['event']) ?></code></td>
          <td><span class="tag <?= $e['transport'] === 'beacon' ? 'kept' : 'new' ?>"><?= html_escape($e['transport']) ?></span></td>
          <td><code><?= html_escape((string) $e['origin']) ?></code></td>
          <td><code><?= html_escape($e['occurred_at']) ?></code></td>
          <td><code><?= html_escape($e['received_at']) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <div class="note">
    <strong>두 전송 경로를 눌러 보세요</strong><br>
    <button type="button" onclick="tp.track('click', {}, 'fetch')">fetch 로 click</button>
    <button type="button" onclick="tp.track('click', {}, 'beacon')">beacon 으로 click</button>
    <button type="button" onclick="var n = tp.queue('impression'); alert('큐 ' + n + '건. 탭을 닫거나 다른 탭으로 가면 beacon 으로 나갑니다.')">impression 큐에 쌓기</button>
    <br><br>
    DevTools Network 탭에서 <strong><code>fetch</code> 는 앞에 OPTIONS 가 붙고 <code>beacon</code> 은 안 붙는 것</strong>을 확인하세요.
    <code>beacon</code> 이 <code>text/plain</code> 을 쓰는 이유이자, 대신 헤더를 못 붙이는 대가입니다.
  </div>

  <?php if ($api_host !== ''): ?>
    <script src="https://<?= html_escape($api_host) ?>/track.js" data-work="<?= html_escape($work) ?>"></script>
  <?php endif; ?>
</main>
</html>
