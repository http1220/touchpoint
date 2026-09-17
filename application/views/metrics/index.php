<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 지표 화면.
|
| 시트 네 장 — 파이프라인 순서로 읽는다.
|   1  읽는 순서 · ① 유입 (방문과 접점 · 배너 CTR)
|   2  ② 전환 → 적재 (전환 · 아웃박스)
|   3  ③ 매체 전송 (도달률 · HTTP 상태 · 재시도 회복)
|   4  ③ 계속 (재시도 분포 · 구간별 소요 시간)
|
| 해설은 칸 안의 나레이션 박스 — 결론 한 문장과 근거 문서. 근거는 docs 가 가진다.
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
		return $v === NULL ? '<span class="muted">—</span>' : number_format((float) $v, 1);
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
			return m_int($n).' / 0 <span class="muted">(—)</span>';
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

$repo = 'https://github.com/http1220/touchpoint/blob/main/';

/** 채널 칸 — 이름 + 가짜 채널 배지. 고정 열이라 모든 표가 같은 모양을 쓴다 */
$channel_cell = function ($ch) use ($fake_channels)
{
	$out = '<code>'.html_escape((string) $ch).'</code>';

	if (m_fake($ch, $fake_channels))
	{
		$out .= ' <span class="badge badge--error">가짜 채널</span>';
	}

	return $out;
};

$thin_badge = function ($d) use ($small_sample)
{
	return m_thin($d, $small_sample) ? ' <span class="badge badge--warning">표본 '.html_escape(m_int($d)).'</span>' : '';
};
?>
<!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '지표 · touchpoint')); ?>
</head>
<body>
<main class="stage">

  <!-- ── 시트 1 · 읽는 순서 · ① 유입 ──────────────── -->
  <div class="sheet layout-metrics-1">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => 3)); ?>

    <header class="masthead">
      <p class="masthead__mark">touchpoint <span class="masthead__sub">지표</span></p>
      <p class="meta-line masthead__note">
        읽기 대상 <code><?= html_escape($read_target) ?></code> (복제본) ·
        서버 시각 <code><?= html_escape($now) ?></code> UTC
      </p>
    </header>

    <section class="panel panel--bottom summary" aria-labelledby="page-title">
      <p class="eyebrow">이 화면을 읽는 순서</p>
      <h1 id="page-title">숫자는 분모 · 표본 · 측정 시각과 함께만</h1>
      <nav class="toc" aria-label="지표 묶음">
        <a href="#inflow">① 유입</a>
        <a href="#convert">② 전환 → 적재</a>
        <a href="#dispatch">③ 매체 전송</a>
      </nav>
      <ol class="rules">
        <li><strong><span class="badge badge--error">가짜 채널</span> 줄을 먼저 지운다.</strong> <code>noop</code> 을 합치면 분모가 가짜로 찬다 — 9,303건 중 9,300건이 <code>noop</code>, 합친 값은 100% 였다.</li>
        <li><strong>비율보다 분모.</strong> <span class="badge badge--warning">표본 N</span>(<?= html_escape((string) $small_sample) ?>건 미만)이면 아직 판정이 아니다.</li>
        <li><strong>도달률 ≠ 반영률.</strong> 이 화면은 매체가 <code>2xx</code> 를 돌려줬다는 것까지만 안다.</li>
        <li><strong>방금 한 일과 다르면</strong> 복제 지연부터 — 복제본 <code><?= html_escape($read_target) ?></code> 에서 읽었다.</li>
      </ol>
      <p class="caption">근거 <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 5장<span aria-hidden="true">↗</span></a></p>
    </section>

    <section class="panel visits" aria-labelledby="visits">
      <p class="eyebrow" id="inflow">① 유입</p>
      <h2 id="visits">방문과 접점</h2>
      <p class="caption"><strong>"접점이 붙은 방문 ÷ 방문"은 보존율이 아니다</strong> — 직접 유입도 마지막 유입을 받아 처음부터 99%대로 뜬다(실측 529 / 530). 분모는 맨 아래 줄, 광고 유입 방문이다.
        <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 5장<span aria-hidden="true">↗</span></a></p>
      <table class="data kv">
        <tbody>
          <tr><th scope="row">방문</th><td class="n"><b><?= html_escape(m_int($funnel['visits'])) ?></b></td><td class="muted">아래 비율의 분모</td></tr>
          <tr><th scope="row">접점이 붙은 방문</th><td class="n"><?= html_escape(m_int($funnel['with_tp'])) ?></td><td><?= m_rate($funnel['with_tp'], $funnel['visits']) ?></td></tr>
          <tr><th scope="row">최초 유입이 있는 방문</th><td class="n"><?= html_escape(m_int($funnel['with_first'])) ?></td><td><?= m_rate($funnel['with_first'], $funnel['visits']) ?></td></tr>
          <tr><th scope="row">마지막 유입이 있는 방문</th><td class="n"><?= html_escape(m_int($funnel['with_last'])) ?></td><td><?= m_rate($funnel['with_last'], $funnel['visits']) ?></td></tr>
          <tr><th scope="row">광고 유입 방문</th><td class="n"><b><?= html_escape(m_int($funnel['with_ad'])) ?></b></td><td><?= m_rate($funnel['with_ad'], $funnel['visits']) ?><?= $thin_badge($funnel['with_ad']) ?></td></tr>
        </tbody>
      </table>
    </section>

    <section class="panel ctr" aria-labelledby="ctr">
      <p class="eyebrow">① 유입</p>
      <h2 id="ctr">배너 노출 · 클릭 <span class="muted">최근 7일 · 노출일 기준</span></h2>
      <p class="caption">화면에 절반 이상 보이면 노출, 누르면 클릭. <strong>클릭은 누른 날이 아니라 노출된 날</strong>로 센다.
        <a href="<?= $repo ?>docs/api-spec.md">api-spec.md<span aria-hidden="true">↗</span></a></p>
      <?php if (empty($ctr)): ?>
        <p class="empty">아직 없습니다. 작품 랜딩의 "다른 작품"을 스크롤하고 눌러 보세요.</p>
      <?php else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="배너 노출·클릭 표 — 가로로 스크롤">
          <table class="data">
            <thead><tr><th scope="col">노출일</th><th scope="col">자리</th><th scope="col" class="n">노출</th><th scope="col" class="n">클릭</th><th scope="col" class="n">CTR</th></tr></thead>
            <tbody>
            <?php foreach ($ctr as $r): ?>
              <tr>
                <td><code><?= html_escape($r['stat_date']) ?></code></td>
                <td><code><?= html_escape($r['slot']) ?></code></td>
                <td class="n"><?= html_escape(m_int($r['impressions'])) ?></td>
                <td class="n"><?= html_escape(m_int($r['clicks'])) ?></td>
                <td class="n"><?= $r['ctr_pct'] === NULL ? '<span class="muted">노출 없음</span>' : html_escape($r['ctr_pct']).'%' ?><?= $thin_badge($r['impressions']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ── 시트 2 · ② 전환 → 적재 ─────────────────── -->
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
  <div class="sheet layout-metrics-2">
    <section class="panel conversions" aria-labelledby="conversions">
      <p class="eyebrow" id="convert">② 전환 → 적재</p>
      <h2 id="conversions">전환</h2>
      <p class="caption"><strong>적재가 100% 가 아니면</strong> 전환은 기록됐는데 보낼 것이 안 만들어진 것 — 같은 트랜잭션 적재 전제가 깨진 자리다.
        <a href="<?= $repo ?>docs/decisions/ADR-003-mysql-outbox.md">ADR-003<span aria-hidden="true">↗</span></a>
        중복 차단은 행을 남기지 않아 이 화면이 셀 수 없다.
        <a href="<?= $repo ?>docs/failure-scenarios.md">failure-scenarios.md D-3<span aria-hidden="true">↗</span></a></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="전환 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">유형</th><th scope="col" class="n">전환</th><th scope="col">적재됨 (/ 전환)</th><th scope="col">전송됨 (/ 전환)</th></tr></thead>
          <tbody>
            <?php if ($conversions === array()): ?>
              <tr><td colspan="4" class="muted">전환이 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($conversions as $c): ?>
                <tr>
                  <td><code><?= html_escape((string) $c['type']) ?></code></td>
                  <td class="n"><?= html_escape(m_int($c['n'])) ?></td>
                  <td><?= m_rate($c['enqueued'], $c['n']) ?><?= $thin_badge($c['n']) ?></td>
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
    </section>

    <section class="panel outbox" aria-labelledby="outbox">
      <p class="eyebrow">② 전환 → 적재</p>
      <h2 id="outbox">아웃박스 적재 <span class="muted">채널 × 상태 · 전체 <?= html_escape(m_int($outbox['grand'])) ?>건</span></h2>
      <p class="caption"><strong><code>failed</code> 0 은 정상</strong> — 재시도는 <code>pending</code> 으로, 포기는 <code>dead</code> 로 가고 <code>failed</code> 를 쓰는 경로가 없다. <code>sending</code> 이 쌓이면 워커가 전송 중에 죽은 것이다.
        <a href="<?= $repo ?>docs/outbox-and-channels.md">outbox-and-channels.md<span aria-hidden="true">↗</span></a></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="아웃박스 적재 표 — 가로로 스크롤">
        <table class="data">
          <thead>
            <tr>
              <th scope="col">채널</th>
              <?php foreach ($outbox_statuses as $st): ?>
                <th scope="col" class="n"><?= html_escape($st) ?></th>
              <?php endforeach; ?>
              <th scope="col" class="n">합계</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($outbox['rows'] === array()): ?>
              <tr><td colspan="<?= count($outbox_statuses) + 2 ?>" class="muted">적재된 것이 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($outbox['rows'] as $ch => $row): ?>
                <tr>
                  <td><?= $channel_cell($ch) ?></td>
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
    </section>
  </div>

  <!-- ── 시트 3 · ③ 매체 전송 ─────────────────────── -->
  <div class="sheet layout-metrics-3">
    <section class="panel reach" aria-labelledby="reach">
      <p class="caption caption--corner">
        <strong>반영률은 이 화면이 알 수 없다.</strong> 도달률은 매체가 <code>2xx</code> 를 돌려줬다는 것까지다.
        GA4 반영률은 <strong>+0h 1% · +25h 95% · +40h 100%</strong> — 문서에 남은 실측값이고 이 화면이 계산한 값이 아니다.
        <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 5-1<span aria-hidden="true">↗</span></a>
      </p>
      <p class="eyebrow" id="dispatch">③ 매체 전송</p>
      <h2 id="reach">도달률 <span class="muted">2xx / 시도</span></h2>
      <p class="meta-line">분모는 전송 시도 수. 무응답(타임아웃·커넥션 실패)은 실패로 센다 — 빼면 분모가 조용히 줄어 도달률이 오른다. 반영률 되읽기: <code>cli/verify ga4</code></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="도달률 표 — 가로로 스크롤">
        <table class="data">
          <thead>
            <tr>
              <th scope="col">채널</th>
              <th scope="col" class="n">시도</th>
              <th scope="col" class="n">아웃박스 행</th>
              <th scope="col" class="n">도달 2xx</th>
              <th scope="col" class="n">실패</th>
              <th scope="col" class="n">무응답</th>
              <th scope="col">도달률 (2xx / 시도)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($dispatch === array()): ?>
              <tr><td colspan="7" class="muted">전송 로그가 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($dispatch as $d): ?>
                <tr>
                  <td><?= $channel_cell($d['channel']) ?></td>
                  <td class="n"><?= html_escape(m_int($d['attempts'])) ?></td>
                  <td class="n"><?= html_escape(m_int($d['outbox_n'])) ?></td>
                  <td class="n"><?= html_escape(m_int($d['ok_n'])) ?></td>
                  <td class="n"><?= html_escape(m_int($d['fail_n'])) ?></td>
                  <td class="n"><?= html_escape(m_int($d['no_reply_n'])) ?></td>
                  <td><?= m_rate($d['ok_n'], $d['attempts']) ?><?= $thin_badge($d['attempts']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel http-status" aria-labelledby="http-status">
      <p class="eyebrow">③ 매체 전송</p>
      <h2 id="http-status">HTTP 상태 분포</h2>
      <p class="caption"><strong>2xx 안에서도 갈린다.</strong> GA4 <code>204</code> 는 운영, <code>200</code> 은 검증 엔드포인트 — 검증은 적재하지 않아 대조하면 누락으로 잡힌다(누락 40건 중 5건).
        <a href="<?= $repo ?>docs/outbox-and-channels.md">outbox-and-channels.md<span aria-hidden="true">↗</span></a></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="HTTP 상태 분포 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">채널</th><th scope="col" class="n">상태</th><th scope="col" class="n">건수</th><th scope="col">비중 (/ 시도)</th></tr></thead>
          <tbody>
            <?php if ($statuses === array()): ?>
              <tr><td colspan="4" class="muted">전송 로그가 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($statuses as $ch => $g): ?>
                <?php foreach ($g['rows'] as $i => $r): ?>
                  <tr>
                    <td><?= $i === 0 ? $channel_cell($ch) : '' ?></td>
                    <td class="n"><?= $r['status'] === NULL ? '<span class="muted">무응답</span>' : '<code>'.html_escape((string) $r['status']).'</code>' ?></td>
                    <td class="n"><?= html_escape(m_int($r['n'])) ?></td>
                    <td><?= m_rate($r['n'], $g['total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel retry-recovery" aria-labelledby="retry-recovery">
      <p class="eyebrow">③ 매체 전송</p>
      <h2 id="retry-recovery">재시도 회복</h2>
      <p class="caption">분모는 <strong>재시도를 겪은 적재</strong>다. 0/0 을 100% 로 적지 않는다 — 실패가 없던 것과 전부 회복한 것은 다른 사실이다.
        <a href="<?= $repo ?>docs/failure-scenarios.md">failure-scenarios.md D-2<span aria-hidden="true">↗</span></a></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="재시도 회복 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">채널</th><th scope="col" class="n">적재</th><th scope="col" class="n">재시도 겪음</th><th scope="col">최종 sent (/ 재시도 겪음)</th></tr></thead>
          <tbody>
            <?php if ($outbox['rows'] === array()): ?>
              <tr><td colspan="4" class="muted">적재된 것이 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($outbox['rows'] as $ch => $row): ?>
                <tr>
                  <td><?= $channel_cell($ch) ?></td>
                  <td class="n"><?= html_escape(m_int($row['_total'])) ?></td>
                  <td class="n"><?= html_escape(m_int($row['_retried'])) ?></td>
                  <td><?= m_rate($row['_recovered'], $row['_retried']) ?><?= $thin_badge($row['_retried']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- ── 시트 4 · ③ 계속 ──────────────────────────── -->
  <?php $segments = array('parse' => 'parse_ms', 'db' => 'db_ms', 'send' => 'send_ms', 'total' => 'total_ms'); ?>
  <div class="sheet layout-metrics-4">
    <section class="panel latency" aria-labelledby="latency">
      <p class="eyebrow">③ 매체 전송</p>
      <h2 id="latency">구간별 소요 시간 <span class="muted">ms · 평균 · p50 · p95 · 최대</span></h2>
      <p class="caption"><strong>평균만 보면 동시성의 대가가 안 보인다</strong> — 워커 1→4 에서 p95 43→83ms.
        구간 합이 <code>total</code> 근처여야 어디가 느린지 말할 수 있다. 표본이 얇으면 p95 는 <strong>가장 느린 값을 빼고</strong> 고른다 — 그래서 최대와 표본을 같이 낸다.
        <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 2장 · 3-2<span aria-hidden="true">↗</span></a></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="구간별 소요 시간 표 — 가로로 스크롤">
        <table class="data">
          <thead>
            <tr>
              <th scope="col">채널</th>
              <th scope="col">구간</th>
              <th scope="col" class="n">평균</th>
              <th scope="col" class="n">p50</th>
              <th scope="col" class="n">p95</th>
              <th scope="col" class="n">최대</th>
              <th scope="col">표본 · 기간 (UTC)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($dispatch === array()): ?>
              <tr><td colspan="7" class="muted">전송 로그가 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($dispatch as $d): ?>
                <?php $first = TRUE; ?>
                <?php foreach ($segments as $key => $label): ?>
                  <tr>
                    <td><?= $first ? $channel_cell($d['channel']) : '' ?></td>
                    <td><code><?= html_escape($label) ?></code></td>
                    <td class="n"><?= m_ms($d[$key.'_avg']) ?></td>
                    <td class="n"><?= m_ms($d[$key.'_p50']) ?></td>
                    <td class="n"><b><?= m_ms($d[$key.'_p95']) ?></b></td>
                    <td class="n"><?= m_ms($d[$key.'_max']) ?></td>
                    <td>
                      <?php if ($first): ?>
                        <?= html_escape(m_int($d['attempts'])) ?>건<?= $thin_badge($d['attempts']) ?>
                        <span class="muted">· <?= html_escape((string) $d['first_at']) ?> ~ <?= html_escape((string) $d['last_at']) ?></span>
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
    </section>

    <section class="panel retry-attempts" aria-labelledby="retry-attempts">
      <p class="eyebrow">③ 매체 전송</p>
      <h2 id="retry-attempts">재시도 분포 <span class="muted">attempt 별</span></h2>
      <p class="caption"><code>attempt</code> 는 선점할 때 1 늘어난 값이라 <strong>첫 전송이 1</strong>이다. 2 이상이 쌓이면 그 채널이 한 번에 통과하지 못하고 있다.</p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="재시도 분포 표 — 가로로 스크롤">
        <table class="data">
          <thead><tr><th scope="col">채널</th><th scope="col" class="n">attempt</th><th scope="col" class="n">건수</th><th scope="col">비중</th><th scope="col">그중 2xx</th></tr></thead>
          <tbody>
            <?php if ($attempts === array()): ?>
              <tr><td colspan="5" class="muted">전송 로그가 없습니다.</td></tr>
            <?php else: ?>
              <?php foreach ($attempts as $ch => $g): ?>
                <?php foreach ($g['rows'] as $i => $r): ?>
                  <tr>
                    <td><?= $i === 0 ? $channel_cell($ch) : '' ?></td>
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
    </section>
  </div>

</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
