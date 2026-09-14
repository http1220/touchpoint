<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 지표 화면.
|
| 표기 함수를 여기 둔다. tp_helper 로 올리면 이 화면 하나 때문에 전역 함수가
| 늘고, 다른 화면이 같은 표기를 따라야 하는 것처럼 보인다. 뷰는 한 번 이상
| 로드될 수 있으므로 function_exists 로 감싼다.
|
| m_rate() 는 HTML 을 돌려준다. 인자는 정수뿐이고 사용자 입력이 닿지 않는다 —
| DB 에서 온 문자열(채널명·상태)은 전부 html_escape() 로 나간다.
*/

if ( ! function_exists('m_int')):
	function m_int($n)
	{
		return number_format((float) $n, 0);
	}
endif;

if ( ! function_exists('m_ms')):
	/** 밀리초. 값이 없으면 0 이 아니라 "—" 다 — 0ms 와 "잰 적 없음" 은 다르다. */
	function m_ms($v)
	{
		return $v === NULL ? '<span class="none">—</span>' : number_format((float) $v, 1);
	}
endif;

if ( ! function_exists('m_rate')):
	/**
	 * **비율은 분모와 함께만 낸다.** "95.2%" 가 아니라 "517 / 543 (95.2%)" — 그리고 반영률은 측정 시각까지.
	 *
	 * 분모가 0이면 비율을 만들지 않는다. 0/0 을 100% 로도 0% 로도 적으면
	 * 아직 아무것도 일어나지 않은 화면이 판정을 내린 것처럼 보인다.
	 */
	function m_rate($n, $d)
	{
		$n = (int) $n;
		$d = (int) $d;

		if ($d <= 0)
		{
			return m_int($n).' / 0 <span class="none">(—)</span>';
		}

		return m_int($n).' / '.m_int($d).' <b>('.number_format($n * 100 / $d, 1).'%)</b>';
	}
endif;

if ( ! function_exists('m_thin')):
	/** 표본이 얇은가. 비율을 읽기 전에 알아야 하는 사실이라 비율 옆에 붙인다. */
	function m_thin($d, $limit)
	{
		return (int) $d > 0 && (int) $d < (int) $limit;
	}
endif;

if ( ! function_exists('m_fake')):
	function m_fake($channel, array $fake)
	{
		return in_array((string) $channel, $fake, TRUE);
	}
endif;
?>
<!doctype html>
<html lang="ko">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>touchpoint · 지표</title>
<style>
  body{font:14px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:2rem;
       background:#0f1115;color:#e6e8ec}
  main{max-width:58rem;margin:0 auto}
  h1{font-size:1.1rem;margin:0 0 .25rem}
  h2{font-size:1rem;margin:2rem 0 .3rem;padding-top:1rem;border-top:1px solid #232733}
  .sub{color:#8b93a1;margin:0 0 1rem}
  .wrap{overflow-x:auto}
  table{width:100%;border-collapse:collapse;margin-bottom:.5rem;white-space:nowrap}
  th,td{text-align:left;padding:.4rem .5rem .4rem .25rem;border-bottom:1px solid #232733;vertical-align:top}
  thead th{color:#8b93a1;font-weight:400}
  td.n,th.n{text-align:right}
  code{background:#1a1e27;padding:.1rem .35rem;border-radius:3px;word-break:break-all}
  b{font-weight:600;color:#fff}
  .none{color:#5b6270}
  .tag{display:inline-block;padding:.05rem .4rem;border-radius:3px;font-size:.85em;margin-left:.3rem}
  .fake{background:#3a2a2a;color:#e09b9b}
  .thin{background:#2a2620;color:#d6b87f}
  .sum td{border-bottom:none;color:#8b93a1}
  .note{margin:.75rem 0 0;padding:.9rem 1rem;background:#171b23;border-left:2px solid #3a4152;color:#a8b0bd}
  .note strong{color:#e6e8ec}
</style>
<main>
  <h1>touchpoint · 지표</h1>
  <p class="sub">
    읽기 대상 <code><?= html_escape($read_target) ?></code> (복제본) ·
    서버 시각 <code><?= html_escape($now) ?></code> UTC ·
    상관 ID <code><?= html_escape($trace_id) ?></code>
  </p>

  <div class="note">
    <strong>이 화면의 규칙</strong><br>
    ① <strong>채널별로 쪼갠다.</strong> 아무 데도 보내지 않는 <code>noop</code> 을 실제 매체와 한 숫자에 합치면
    분모가 가짜로 채워진다 — 실제로 전송 로그 9303건 중 9300건이 <code>noop</code> 이었고, 합친 값은 100% 였다.<br>
    ② <strong>도달률과 반영률은 다른 숫자다.</strong> 아래 표의 성공률은 전부 <strong>도달률</strong>(매체가 <code>2xx</code> 를 돌려줬다)이다.
    <strong>반영률</strong>(매체 보고서에 실제로 있다)은 이 DB 에 답이 없다 — 되읽어 대조해야 한다.<br>
    ③ <strong>비율은 분모와 함께 낸다.</strong> 표본이 <?= html_escape((string) $small_sample) ?>건 미만이면
    <span class="tag thin">표본 N</span> 을 붙인다. 1건이 1%p 이상을 움직이는 구간이기 때문이다.<br>
    근거: <code>docs/benchmarks.md</code> 5장.
  </div>

  <h2>아웃박스 적재 — 채널 × 상태</h2>
  <p class="sub">분모는 채널별 적재 건수다. 전체 <?= html_escape(m_int($outbox['grand'])) ?>건.</p>

  <div class="wrap">
  <table>
    <thead>
      <tr>
        <th>채널</th>
        <?php foreach ($outbox_statuses as $st): ?>
          <th class="n"><?= html_escape($st) ?></th>
        <?php endforeach; ?>
        <th class="n">합계</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($outbox['rows'] === array()): ?>
        <tr><td colspan="<?= count($outbox_statuses) + 2 ?>" class="none">적재된 것이 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($outbox['rows'] as $ch => $row): ?>
          <tr>
            <td>
              <code><?= html_escape((string) $ch) ?></code>
              <?php if (m_fake($ch, $fake_channels)): ?><span class="tag fake">가짜 채널</span><?php endif; ?>
            </td>
            <?php foreach ($outbox_statuses as $st): ?>
              <td class="n"><?= html_escape(m_int($row[$st])) ?></td>
            <?php endforeach; ?>
            <td class="n"><b><?= html_escape(m_int($row['_total'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
        <tr class="sum">
          <td>합계</td>
          <?php foreach ($outbox_statuses as $st): ?>
            <td class="n"><?= html_escape(m_int($outbox['totals'][$st])) ?></td>
          <?php endforeach; ?>
          <td class="n"><?= html_escape(m_int($outbox['grand'])) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="note">
    <code>failed</code> 가 0인 것은 정상이다. 재시도 대기는 <code>pending</code> 으로 돌아가고
    (<code>Outbox_model::applyResult()</code>), 포기는 <code>dead</code> 로 간다 —
    지금 코드에 <code>failed</code> 를 쓰는 경로가 없다. 스키마에만 남아 있는 상태다.<br>
    <code>sending</code> 이 쌓여 있으면 워커가 전송 중에 죽었을 수 있다. 좀비 회수(<code>reclaimZombies()</code>)가
    <code>pending</code> 으로 되돌린다.
  </div>

  <h2>채널별 전송 — 도달률</h2>
  <p class="sub">
    <code>dispatch_log</code> 기준. <strong>분모는 전송 시도 수</strong>이고,
    무응답(타임아웃·커넥션 실패)은 실패로 센다 — 빼면 분모가 조용히 줄어 도달률이 올라간다.
  </p>

  <div class="wrap">
  <table>
    <thead>
      <tr>
        <th>채널</th>
        <th class="n">시도</th>
        <th class="n">아웃박스 행</th>
        <th class="n">도달 2xx</th>
        <th class="n">실패</th>
        <th class="n">무응답</th>
        <th>도달률 (2xx / 시도)</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($dispatch === array()): ?>
        <tr><td colspan="7" class="none">전송 로그가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($dispatch as $d): ?>
          <tr>
            <td>
              <code><?= html_escape((string) $d['channel']) ?></code>
              <?php if (m_fake($d['channel'], $fake_channels)): ?><span class="tag fake">가짜 채널</span><?php endif; ?>
            </td>
            <td class="n"><?= html_escape(m_int($d['attempts'])) ?></td>
            <td class="n"><?= html_escape(m_int($d['outbox_n'])) ?></td>
            <td class="n"><?= html_escape(m_int($d['ok_n'])) ?></td>
            <td class="n"><?= html_escape(m_int($d['fail_n'])) ?></td>
            <td class="n"><?= html_escape(m_int($d['no_reply_n'])) ?></td>
            <td>
              <?= m_rate($d['ok_n'], $d['attempts']) ?>
              <?php if (m_thin($d['attempts'], $small_sample)): ?>
                <span class="tag thin">표본 <?= html_escape(m_int($d['attempts'])) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="note">
    <strong>반영률은 이 화면이 알 수 없다.</strong><br>
    도달률은 "매체가 <code>2xx</code> 를 돌려줬다" 까지만 말한다. GA4 운영 엔드포인트는
    페이로드가 틀려도 <code>204</code> 를 준다 — 보낸 직후에는 도달률 100% 와 반영률 1% 가 같은 전송을 두고 동시에 참이었다.<br>
    반영률은 <strong>매체에서 되읽어 한 건씩 맞대야</strong> 나오고, 그 결과를 남기는 자리가 지금 스키마에 없다.
    그래서 화면이 지어내지 않고 명령만 적는다 —
    <code>php public/index.php cli/verify ga4 2026-09-12 2026-09-13</code><br>
    문서에 남은 실측값: GA4 <strong>543 / 543 (100.0%)</strong>, 전송 <strong>+40시간</strong> 시점
    (<code>docs/benchmarks.md</code> 5-1). <span class="none">이 화면이 계산한 값이 아니다.</span><br>
    <strong>반영률은 언제 물었는지와 함께만 뜻이 있다.</strong> +0h 1% · +25h 95% · +40h 100% 였고,
    +25h 에서 멈춘 속도를 보고 "4.8% 영구 유실" 로 잘못 판정한 적이 있다 —
    <strong>매체의 처리 창(GA4 최대 48시간)을 다 기다린 뒤</strong> 돌린다.<br>
    <code>noop</code> 에는 반영률이라는 개념이 없다. 아무 데도 보내지 않으므로 대조할 보고서가 없다.
  </div>

  <h2>채널별 HTTP 상태 분포</h2>
  <p class="sub">분모는 채널별 전송 시도 수. <strong>2xx 안에서도 갈린다</strong>는 것이 이 표의 용도다.</p>

  <div class="wrap">
  <table>
    <thead>
      <tr><th>채널</th><th class="n">상태</th><th class="n">건수</th><th>비중 (건수 / 시도)</th></tr>
    </thead>
    <tbody>
      <?php if ($statuses === array()): ?>
        <tr><td colspan="4" class="none">전송 로그가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($statuses as $ch => $g): ?>
          <?php foreach ($g['rows'] as $i => $r): ?>
            <tr>
              <td>
                <?php if ($i === 0): ?>
                  <code><?= html_escape((string) $ch) ?></code>
                  <?php if (m_fake($ch, $fake_channels)): ?><span class="tag fake">가짜 채널</span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="n">
                <?= $r['status'] === NULL
                      ? '<span class="none">무응답</span>'
                      : '<code>'.html_escape((string) $r['status']).'</code>' ?>
              </td>
              <td class="n"><?= html_escape(m_int($r['n'])) ?></td>
              <td><?= m_rate($r['n'], $g['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="note">
    GA4 는 <strong><code>204</code> 가 운영 엔드포인트, <code>200</code> 이 검증 엔드포인트</strong>(<code>GA4_DEBUG=true</code>)다.
    둘 다 2xx 인데 <code>200</code> 쪽은 <strong>검사만 하고 적재하지 않는다</strong> — 대조하면 영원히 누락으로 잡힌다.
    실제로 누락 40건 중 5건이 그것이었다. 스키마에 "어느 엔드포인트로 갔는가" 를 남기는 자리가 없어
    지금은 이 상태 코드가 그 구분을 대신한다.
  </div>

  <h2>구간별 소요 시간 — 평균과 p95</h2>
  <p class="sub">
    단위 ms. 구간을 나눠 적는 이유는 <strong>합이 맞아야 어디가 느린지 알기 때문</strong>이다 —
    <code>parse</code> + <code>db</code> + <code>send</code> 가 <code>total</code> 근처에 와야 한다.
    p50·p95 는 <strong>같은 표본을 정렬해 순위로 고른 값</strong>이다(<code>PERCENT_RANK()</code>, nearest-rank).
    표본이 얇으면 p95 가 최댓값과 같아진다 — 그래서 표본 수를 옆에 낸다.
  </p>

  <?php $segments = array('parse' => 'parse_ms', 'db' => 'db_ms', 'send' => 'send_ms', 'total' => 'total_ms'); ?>

  <div class="wrap">
  <table>
    <thead>
      <tr>
        <th>채널</th>
        <th>구간</th>
        <th class="n">평균</th>
        <th class="n">p50</th>
        <th class="n">p95</th>
        <th class="n">최대</th>
        <th>표본 · 기간 (UTC)</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($dispatch === array()): ?>
        <tr><td colspan="7" class="none">전송 로그가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($dispatch as $d): ?>
          <?php $first = TRUE; ?>
          <?php foreach ($segments as $key => $label): ?>
            <tr>
              <td>
                <?php if ($first): ?>
                  <code><?= html_escape((string) $d['channel']) ?></code>
                  <?php if (m_fake($d['channel'], $fake_channels)): ?><span class="tag fake">가짜 채널</span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td><code><?= html_escape($label) ?></code></td>
              <td class="n"><?= m_ms($d[$key.'_avg']) ?></td>
              <td class="n"><?= m_ms($d[$key.'_p50']) ?></td>
              <td class="n"><b><?= m_ms($d[$key.'_p95']) ?></b></td>
              <td class="n"><?= m_ms($d[$key.'_max']) ?></td>
              <td>
                <?php if ($first): ?>
                  <?= html_escape(m_int($d['attempts'])) ?>건
                  <?php if (m_thin($d['attempts'], $small_sample)): ?>
                    <span class="tag thin">표본 <?= html_escape(m_int($d['attempts'])) ?></span>
                  <?php endif; ?>
                  <span class="none">· <?= html_escape((string) $d['first_at']) ?> ~ <?= html_escape((string) $d['last_at']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php $first = FALSE; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="note">
    <strong>평균만 보면 동시성의 대가가 안 보인다.</strong> 워커를 1개에서 4개로 올렸을 때
    처리량은 1.7배인데 <strong>p95 는 43 → 83ms, p99 는 54 → 111ms 로 두 배</strong>였다 —
    평균은 그동안 44.9 하나뿐이었다. 타임아웃(<code>DISPATCH_TIMEOUT_MS</code>)을 정할 때 보는 값은 꼬리다.<br>
    <code>noop</code> 은 네트워크가 없어 <code>send</code> 가 0이고 <code>db</code> 가 <code>total</code> 의 거의 전부다.
    실제 매체를 붙이면 비용의 주인이 바뀐다 — GA4 500건에서 <code>send</code> 가 87%, <code>db</code> 는 12% 였다.
    <strong>두 채널의 구간 비율이 같아 보이면 그쪽이 이상한 것이다.</strong>
  </div>

  <h2>재시도 회복 — 재시도 후 최종 성공률</h2>
  <p class="sub">
    <strong>분모는 "재시도를 겪은 적재"</strong>(<code>attempt &gt;= 2</code>)다.
    전체 적재로 나누면 한 번에 통과한 것까지 분모에 들어가, 백오프가 듣든 말든 비율이 100%에 붙는다.
  </p>

  <div class="wrap">
  <table>
    <thead>
      <tr><th>채널</th><th class="n">적재</th><th class="n">재시도 겪음</th><th>그중 최종 sent (/ 재시도 겪음)</th></tr>
    </thead>
    <tbody>
      <?php if ($outbox['rows'] === array()): ?>
        <tr><td colspan="4" class="none">적재된 것이 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($outbox['rows'] as $ch => $row): ?>
          <tr>
            <td>
              <code><?= html_escape((string) $ch) ?></code>
              <?php if (m_fake($ch, $fake_channels)): ?><span class="tag fake">가짜 채널</span><?php endif; ?>
            </td>
            <td class="n"><?= html_escape(m_int($row['_total'])) ?></td>
            <td class="n"><?= html_escape(m_int($row['_retried'])) ?></td>
            <td>
              <?= m_rate($row['_recovered'], $row['_retried']) ?>
              <?php if (m_thin($row['_retried'], $small_sample)): ?>
                <span class="tag thin">표본 <?= html_escape(m_int($row['_retried'])) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="note">
    재시도를 겪은 적재가 0이면 이 줄은 <span class="none">(—)</span> 다. <strong>0/0 을 100% 로 적지 않는다</strong> —
    아직 아무것도 실패하지 않은 상태와 "실패했는데 전부 회복했다" 는 다른 사실이다.
    실패 경로를 일부러 보려면 <code>NOOP_OUTCOME=retry</code> 로 주입한다(→ <code>docs/failure-scenarios.md</code> D-2).
  </div>

  <h2>재시도 분포</h2>
  <p class="sub">
    분모는 채널별 전송 시도 수. <code>attempt</code> 는 선점할 때 1 늘어난 값이라 <strong>첫 전송이 1</strong>이다.
    2 이상이 쌓이면 그 채널이 한 번에 통과하지 못하고 있다는 뜻이다.
  </p>

  <div class="wrap">
  <table>
    <thead>
      <tr><th>채널</th><th class="n">attempt</th><th class="n">건수</th><th>비중</th><th>그중 2xx</th></tr>
    </thead>
    <tbody>
      <?php if ($attempts === array()): ?>
        <tr><td colspan="5" class="none">전송 로그가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($attempts as $ch => $g): ?>
          <?php foreach ($g['rows'] as $i => $r): ?>
            <tr>
              <td>
                <?php if ($i === 0): ?>
                  <code><?= html_escape((string) $ch) ?></code>
                  <?php if (m_fake($ch, $fake_channels)): ?><span class="tag fake">가짜 채널</span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="n"><?= html_escape(m_int($r['attempt'])) ?></td>
              <td class="n"><?= html_escape(m_int($r['n'])) ?></td>
              <td><?= m_rate($r['n'], $g['total']) ?></td>
              <td><?= m_rate($r['ok_n'], $r['n']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <h2>전환</h2>
  <p class="sub">
    분모는 전환 건수다. <strong>적재</strong>는 그 전환에 아웃박스 행이 하나라도 생겼는가,
    <strong>전송</strong>은 그중 <code>sent</code> 가 하나라도 있는가다. 식별자·회원 정보는 내리지 않는다.
  </p>

  <?php
    // 합계는 쿼리를 하나 더 쓰지 않고 여기서 더한다 —
    // 유형별 행이 이미 전부 와 있어서 DB 를 한 번 더 갈 이유가 없다.
    $conv_total = 0;
    $conv_enq   = 0;
    $conv_sent  = 0;

    foreach ($conversions as $c)
    {
        $conv_total += (int) $c['n'];
        $conv_enq   += (int) $c['enqueued'];
        $conv_sent  += (int) $c['any_sent'];
    }
  ?>

  <div class="wrap">
  <table>
    <thead>
      <tr><th>유형</th><th class="n">전환</th><th>적재됨 (/ 전환)</th><th>전송됨 (/ 전환)</th></tr>
    </thead>
    <tbody>
      <?php if ($conversions === array()): ?>
        <tr><td colspan="4" class="none">전환이 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($conversions as $c): ?>
          <tr>
            <td><code><?= html_escape((string) $c['type']) ?></code></td>
            <td class="n"><?= html_escape(m_int($c['n'])) ?></td>
            <td>
              <?= m_rate($c['enqueued'], $c['n']) ?>
              <?php if (m_thin($c['n'], $small_sample)): ?>
                <span class="tag thin">표본 <?= html_escape(m_int($c['n'])) ?></span>
              <?php endif; ?>
            </td>
            <td><?= m_rate($c['any_sent'], $c['n']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="sum">
          <td>합계</td>
          <td class="n"><?= html_escape(m_int($conv_total)) ?></td>
          <td><?= m_rate($conv_enq, $conv_total) ?></td>
          <td><?= m_rate($conv_sent, $conv_total) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="note">
    적재율이 100% 가 아니면 <strong>전환은 기록됐는데 보낼 것이 만들어지지 않은 것</strong>이다.
    같은 트랜잭션 안에서 적재하기로 한 전제(ADR-003)가 깨진 자리이므로, 그때는 이 줄이 먼저 말해 줘야 한다.
    <code>CHANNELS</code> 가 비어 있어도 같은 모양이 된다 — 설정부터 본다.<br>
    <strong>중복 전환 차단 건수는 이 화면이 셀 수 없다.</strong> 차단은 <code>uq_dedup</code> 이 하고,
    막힌 요청은 <strong>행을 남기지 않는다</strong> — 남았다면 막지 못한 것이다.
    세려면 차단을 세는 자리를 따로 만들어야 한다. 지금은 시험으로만 확인했다
    (동시 8개 → 1행, 3회 · <code>docs/failure-scenarios.md</code> D-3).
  </div>

  <h2>방문과 접점</h2>
  <p class="sub">분모는 방문 수 <?= html_escape(m_int($funnel['visits'])) ?>건.</p>

  <div class="wrap">
  <table>
    <tbody>
      <tr>
        <th>방문</th>
        <td class="n"><b><?= html_escape(m_int($funnel['visits'])) ?></b></td>
        <td class="none">이 줄이 아래 비율의 분모다</td>
      </tr>
      <tr>
        <th>접점이 붙은 방문</th>
        <td class="n"><?= html_escape(m_int($funnel['with_tp'])) ?></td>
        <td><?= m_rate($funnel['with_tp'], $funnel['visits']) ?></td>
      </tr>
      <tr>
        <th>first 접점이 있는 방문</th>
        <td class="n"><?= html_escape(m_int($funnel['with_first'])) ?></td>
        <td><?= m_rate($funnel['with_first'], $funnel['visits']) ?></td>
      </tr>
      <tr>
        <th>last 접점이 있는 방문</th>
        <td class="n"><?= html_escape(m_int($funnel['with_last'])) ?></td>
        <td><?= m_rate($funnel['with_last'], $funnel['visits']) ?></td>
      </tr>
      <tr>
        <th>유입 파라미터가 실린 접점을 가진 방문</th>
        <td class="n"><b><?= html_escape(m_int($funnel['with_ad'])) ?></b></td>
        <td>
          <?= m_rate($funnel['with_ad'], $funnel['visits']) ?>
          <?php if (m_thin($funnel['with_ad'], $small_sample)): ?>
            <span class="tag thin">표본 <?= html_escape(m_int($funnel['with_ad'])) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
  </div>

  <div class="note">
    <strong>"접점이 붙은 방문 ÷ 전체 방문" 은 어트리뷰션 보존율이 아니다.</strong>
    직접 유입도 <code>TouchpointResolver</code> 규칙 4에 따라 <code>last</code> 접점을 하나 받으므로
    이 비율은 처음부터 99%대로 뜬다. 실측 <strong>529 / 530 = 99.8%</strong> 는 시스템이 건강하다는 뜻이 아니라
    <strong>분모를 잘못 골랐다</strong>는 뜻이었다 — 목표치를 처음부터 넘고 있는 지표는 대개 이 경우다.<br>
    고친 정의는 <strong>"광고 유입 방문 중 접점이 보존된 비율"</strong> 이고, 그 분모가 마지막 줄이다.
    다만 <strong>보존 여부는 이 화면이 판정하지 못한다</strong> — 접점을 잃은 광고 유입 방문은 DB 에
    "광고로 들어왔다" 는 흔적을 남기지 않아서, 분자와 분모가 같은 테이블에서 나온다.
    지금 화면은 <strong>분모만</strong> 낸다. 보존율은 유입과 저장을 양쪽에서 세는 시험으로만 말할 수 있다
    (<code>docs/benchmarks.md</code> 5장, 표본 20/20).
  </div>

  <h2>배너 노출 · 클릭 — 자리별 CTR (최근 7일, 노출일 기준)</h2>
  <p class="sub">
    <code>POST /impression</code> 이 화면에 절반 이상 보인 배너를, <code>GET /click</code> 이 누른 배너를 센다.
    클릭은 <strong>누른 날이 아니라 노출된 날</strong>(링크의 <code>sd</code>)로 잡는다.
  </p>

  <?php if (empty($ctr)): ?>
    <p class="sub none">아직 없습니다. 작품 랜딩 아래 "다른 작품" 을 스크롤하고 눌러 보세요.</p>
  <?php else: ?>
  <div class="wrap">
  <table>
    <thead><tr><th>노출일</th><th>자리</th><th class="n">노출</th><th class="n">클릭</th><th class="n">CTR</th></tr></thead>
    <tbody>
    <?php foreach ($ctr as $r): ?>
      <tr>
        <td><code><?= html_escape($r['stat_date']) ?></code></td>
        <td><code><?= html_escape($r['slot']) ?></code></td>
        <td class="n"><?= html_escape(m_int($r['impressions'])) ?></td>
        <td class="n"><?= html_escape(m_int($r['clicks'])) ?></td>
        <td class="n">
          <?= $r['ctr_pct'] === NULL ? '<span class="none">노출 없음</span>' : html_escape($r['ctr_pct']).'%' ?>
          <?php if (m_thin($r['impressions'], $small_sample)): ?><span class="tag thin">표본 <?= html_escape(m_int($r['impressions'])) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <div class="note">
    <strong>이 화면을 읽는 순서</strong><br>
    ① 채널 줄에서 <span class="tag fake">가짜 채널</span> 을 먼저 지운다 — 남은 것만 매체 이야기다.<br>
    ② 비율보다 <strong>분모</strong>를 본다. <span class="tag thin">표본 N</span> 이 붙어 있으면 비율은 아직 판정이 아니다.<br>
    ③ 도달률이 100% 라도 끝이 아니다. <strong>반영률은 되읽기로만</strong> 나온다.<br>
    ④ 숫자가 방금 한 일과 다르면 <strong>복제 지연</strong>부터 의심한다 — 이 화면은 복제본
    <code><?= html_escape($read_target) ?></code> 에서 읽었다.
  </div>
</main>
</html>
