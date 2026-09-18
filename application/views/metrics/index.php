<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 지표 화면.
|
| 시트 여섯 장 — 파이프라인 순서로 읽는다.
|   1  읽는 순서 + **결론 세 줄** · ① 유입 (방문과 접점)
|   2  광고가 만든 것 (매체별 전환 · 귀속 기준 둘 · 배너 노출·클릭) ← 이 시스템이 답하려는 질문
|   3  반영률 (매체를 되읽은 실측 기록)
|   4  ② 전환 → 적재 (전환 · 아웃박스)
|   5  ③ 매체 전송 (도달률 · HTTP 상태 · 재시도 회복)
|   6  ③ 계속 (재시도 분포 · 구간별 소요 시간)
|
| 2번은 파이프라인의 한 단계가 아니라 **그 파이프라인이 무엇을 위한 것인지**를
| 말하는 자리다. 적재율·도달률이 아무리 좋아도 "어느 광고가 결제를 만들었나"에
| 답하지 못하면 이 시스템은 값을 하지 않는다 — 2026-09-18.
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

    <?php
      /*
       * 결론 세 줄 — 첫 화면에서 "그래서 뭐가 됐나" 에 먼저 답한다.
       * 새 질의를 쓰지 않는다. 아래 표들이 쓰는 값을 여기서 한 번 더 합칠 뿐이다.
       *
       * 도달률은 **가짜 채널을 뺀 값**이다. 이 화면의 규칙 ① 을 첫 줄이 어기면
       * 아래에서 그 규칙을 적어 봐야 소용이 없다 — noop 을 합치면 100% 가 된다.
       */
      $real_ok = 0;
      $real_try = 0;

      foreach ($dispatch as $d)
      {
          if (m_fake($d['channel'], $fake_channels)) { continue; }

          $real_ok  += (int) $d['ok_n'];
          $real_try += (int) $d['attempts'];
      }
    ?>
    <?php /* 결론 세 줄이 들어오면서 칸이 길어졌다 — 아래로 밀어 두던 panel--bottom 을 뗀다.
             짧은 칸일 때는 아래 정렬이 옆 칸과 바닥선을 맞춰 줬지만, 지금은 위쪽에 빈 띠만 남는다 */ ?>
    <section class="panel summary" aria-labelledby="page-title">
      <h1 id="page-title">숫자는 분모 · 표본 · 측정 시각과 함께만</h1>

      <table class="data kv headline">
        <caption class="sr-only">이 화면의 결론 세 줄</caption>
        <tbody>
          <tr>
            <th scope="row">매체 도달률 <span class="muted">가짜 채널 뺀 값</span></th>
            <td><?= m_rate($real_ok, $real_try) ?><?= $thin_badge($real_try) ?></td>
          </tr>
          <tr>
            <th scope="row">매체 반영률 <span class="muted">되읽은 기록</span></th>
            <td><?= m_rate(543, 543) ?> <span class="muted">+40h 에 대조</span></td>
          </tr>
          <tr>
            <th scope="row">광고가 만든 결제</th>
            <td>
              <?= m_rate($ad['with_ad'], $ad['total']) ?><?= $thin_badge($ad['with_ad']) ?>
              <?php if ($ad['table']['value_total'] > 0): ?>
                · <?= html_escape(m_int($ad['table']['value_total'])) ?> <span class="muted"><?= html_escape(implode(' · ', $ad['table']['currencies'])) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="meta-line">자세히 — <a href="#attribution">매체별 표</a> · <a href="#reflected">반영률을 어떻게 알았나</a></p>
      <p class="caption">첫 줄만 지금 센 값이다. 반영률은 매체를 되읽어야 알 수 있어 <strong>측정 시각이 붙고</strong>, 셋째 줄 분모는 전환 전체다.</p>
      <nav class="toc" aria-label="지표 묶음">
        <a href="#inflow">① 유입</a>
        <a href="#attribution">광고가 만든 것</a>
        <a href="#convert">② 전환 → 적재</a>
        <a href="#dispatch">③ 매체 전송</a>
      </nav>
      <ol class="rules">
        <li><strong><span class="badge badge--error">가짜 채널</span> 줄을 먼저 지운다.</strong> 합치면 분모가 가짜로 찬다 — 9,303건 중 9,300건이 <code>noop</code> 이었다.</li>
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
  </div>

  <!-- ── 시트 2 · 광고가 만든 것 ─────────────────── -->
  <?php
    $ad_table = $ad['table'];
    // 광고 접점이 붙은 전환이 분모다. 전환 전체를 분모로 쓰면 "광고가 다 만들었다" 로 읽힌다
    $ad_rows  = $ad_table['rows'];
    $ad_money = $ad_table['value_total'] > 0;
  ?>
  <div class="sheet layout-metrics-ad">
    <section class="panel ad-attr" aria-labelledby="ad-attr">
      <p class="eyebrow" id="attribution">광고 → 결제</p>
      <h2 id="ad-attr">광고가 만든 것 <span class="muted">매체별 · 귀속 기준 둘</span></h2>

      <p class="caption"><strong>같은 결제도 어느 접점에 붙이냐에 따라 매체가 달라진다.</strong>
        최초 유입으로 세면 처음 데려온 매체가, 마지막 유입으로 세면 마지막에 밀어 준 매체가 가져간다.
        정산에서 다투는 자리라 <strong>한 기준을 고르지 않고 둘을 나란히</strong> 놓는다.
        결제 자체가 어느 방문에 붙는지는 또 다른 결정이다 — 결제 시점 방문이 있으면 그쪽, 없으면 가입 접점.
        <a href="<?= $repo ?>docs/plan-payment-webhook.md">plan-payment-webhook.md 6장<span aria-hidden="true">↗</span></a></p>

      <table class="data kv">
        <tbody>
          <tr><th scope="row">전환</th><td class="n"><b><?= html_escape(m_int($ad['total'])) ?></b></td><td class="muted">아래 비율의 분모</td></tr>
          <tr><th scope="row">방문이 붙은 전환</th><td class="n"><?= html_escape(m_int($ad['with_visit'])) ?></td><td><?= m_rate($ad['with_visit'], $ad['total']) ?></td></tr>
          <tr><th scope="row">광고 접점까지 붙은 전환</th><td class="n"><b><?= html_escape(m_int($ad['with_ad'])) ?></b></td><td><?= m_rate($ad['with_ad'], $ad['total']) ?><?= $thin_badge($ad['with_ad']) ?> <span class="muted">← 아래 표의 분모</span></td></tr>
        </tbody>
      </table>

      <?php if ($ad_rows === array()): ?>
        <p class="empty">광고 접점이 붙은 전환이 없습니다. 유입 파라미터를 달고 들어온 방문에서 결제가 일어나야 이 표에 줄이 생깁니다.</p>
      <?php else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="매체별 전환 표 — 가로로 스크롤">
          <table class="data data--tight">
            <thead>
              <tr>
                <th scope="col">매체 <code>utm_source</code></th>
                <th scope="col" class="n">최초 유입 기준</th>
                <th scope="col" class="n">마지막 유입 기준</th>
                <th scope="col" class="n">차이</th>
                <?php if ($ad_money): ?><th scope="col" class="n">결제 금액 <span class="muted">마지막 기준</span></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ad_rows as $r): ?>
                <tr>
                  <td><code><?= html_escape($r['source']) ?></code></td>
                  <td class="n"><?= html_escape(m_int($r['first'])) ?></td>
                  <td class="n"><?= html_escape(m_int($r['last'])) ?></td>
                  <td class="n"><?php $d = $r['last'] - $r['first']; ?>
                    <?php if ($d === 0): ?><span class="muted">—</span>
                    <?php else: ?><span class="badge badge--warning"><?= $d > 0 ? '+' : '−' ?><?= html_escape(m_int(abs($d))) ?></span><?php endif; ?>
                  </td>
                  <?php if ($ad_money): ?>
                    <td class="n"><?= html_escape(m_int($r['value_minor'])) ?><?= $r['currency'] === NULL ? '' : ' <span class="muted">'.html_escape($r['currency']).'</span>' ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              <tr class="sum">
                <td>합계</td>
                <td class="n"><?= html_escape(m_int($ad_table['first_total'])) ?></td>
                <td class="n"><?= html_escape(m_int($ad_table['last_total'])) ?></td>
                <td class="n"><span class="muted">—</span></td>
                <?php if ($ad_money): ?>
                  <td class="n"><?= html_escape(m_int($ad_table['value_total'])) ?><?= $ad_table['currencies'] === array() ? '' : ' <span class="muted">'.html_escape(implode(' · ', $ad_table['currencies'])).'</span>' ?></td>
                <?php endif; ?>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="caption">
          <?php if ($ad_table['moved']): ?>
            <strong>두 기준의 합계는 같은데 매체별로는 옮겨 갔다.</strong> 이 표에서 "차이" 가 붙은 줄이 그 자리다 — 기준을 말하지 않은 전환 수는 뜻이 없다.
          <?php else: ?>
            <strong>두 기준이 같은 값을 냈다.</strong> 지금 표본에서는 한 방문 안에서 최초와 마지막 유입이 같은 매체라는 뜻이고, 매체를 갈아타며 들어온 방문이 쌓이면 갈라진다.
          <?php endif; ?>
          금액은 <strong>마지막 유입 기준으로만 한 번</strong> 더한다 — 두 기준에 다 더하면 매출이 두 배가 된다.
          KRW 의 minor unit 은 원이다(9,900원 = <code>9900</code>).
        </p>
        <p class="caption"><strong>매체 이름이 <code>home</code>·<code>demo</code> 같은 것은 광고를 실제로 집행하지 않기 때문이다.</strong>
          이 줄들은 시연·시험에서 만든 유입이고, <code>utm_source</code> 자리에 그 이름이 그대로 들어온다.
          실제 집행에서는 매체가 붙인 값이 같은 자리에 들어온다 — 화면과 질의는 그대로다.
          대부분의 전환에 방문이 없는 것도 같은 이유다: 부하 시험으로 서버에서 직접 만든 전환에는 쿠키가 없다.</p>
      <?php endif; ?>
    </section>

    <section class="panel ctr" aria-labelledby="ctr">
      <p class="eyebrow">광고 → 클릭</p>
      <h2 id="ctr">배너 노출 · 클릭 <span class="muted">최근 7일 · 노출일 기준</span></h2>
      <p class="caption">화면에 절반 이상 보이면 노출, 누르면 클릭. <strong>클릭은 누른 날이 아니라 노출된 날</strong>로 센다.
        <a href="<?= $repo ?>docs/api-spec.md">api-spec.md<span aria-hidden="true">↗</span></a></p>
      <?php if (empty($ctr)): ?>
        <p class="empty">아직 없습니다. 작품 랜딩의 "다른 작품"을 스크롤하고 눌러 보세요.</p>
      <?php else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="배너 노출·클릭 표 — 가로로 스크롤">
          <table class="data data--tight">
            <thead><tr><th scope="col">노출일</th><th scope="col">자리</th><th scope="col" class="n">노출</th><th scope="col" class="n">클릭</th><th scope="col">CTR (클릭 / 노출)</th></tr></thead>
            <tbody>
            <?php foreach ($ctr as $r): ?>
              <tr>
                <td><code><?= html_escape($r['stat_date']) ?></code></td>
                <td><code><?= html_escape($r['slot']) ?></code></td>
                <td class="n"><?= html_escape(m_int($r['impressions'])) ?></td>
                <td class="n"><?= html_escape(m_int($r['clicks'])) ?></td>
                <?php /* 이 열만 퍼센트 하나였다. 화면의 규칙 ③(비율은 분모와 함께)을 여기도 지킨다 —
                         모델의 ctr_pct 대신 m_rate 로 같은 모양을 만든다. 노출 0 이면 비율을 만들지 않는다 */ ?>
                <td><?= m_rate($r['clicks'], $r['impressions']) ?><?= (int) $r['impressions'] === 0 ? ' <span class="muted">노출 없음</span>' : '' ?><?= $thin_badge($r['impressions']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  </div>

  <!-- ── 시트 3 · 반영률 ─────────────────────────── -->
  <div class="sheet layout-metrics-ad">
    <?php /* 이 칸만 라이브 값이 아니다. 매체를 되읽어야 알 수 있는 숫자라
             측정 시각과 방법을 함께 적고, 화면이 스스로 센 값과 섞이지 않게 갈라 둔다 */ ?>
    <section class="panel reflected" aria-labelledby="reflected">
      <p class="eyebrow">되돌려 보낸 것이 매체에 남았는가</p>
      <h2 id="reflected">반영률 <span class="muted">실측 기록 · 이 화면이 센 값이 아니다</span></h2>

      <p class="caption"><strong>도달률은 매체가 <code>2xx</code> 를 줬다는 말이고, 반영률은 매체 보고서에 실제로 있다는 말이다.</strong>
        같은 전송을 두고 <strong>+0h 에는 도달률 100% 와 반영률 1.2% 가 동시에 참</strong>이었다.
        그래서 이 시스템은 GA4 Data API 로 <code>transaction_id</code> 를 한 건씩 되맞댄다.
        <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 5-1<span aria-hidden="true">↗</span></a></p>

      <table class="data data--tight">
        <thead><tr><th scope="col">전송 후 경과</th><th scope="col" class="n">누락</th><th scope="col">반영률 (매체가 집계한 것 / 보낸 것)</th><th scope="col">그때 내린 판단</th></tr></thead>
        <tbody>
          <tr><td><code>+0h</code></td><td class="n">502</td><td><?= m_rate(6, 508) ?><span class="muted"> 그때까지 보낸 것</span></td><td class="muted">"매체가 99%를 버린다" — <strong>오판</strong></td></tr>
          <tr><td><code>+7h</code></td><td class="n">35</td><td><?= m_rate(508, 543) ?></td><td class="muted">—</td></tr>
          <tr><td><code>+25h</code></td><td class="n">26</td><td><?= m_rate(517, 543) ?></td><td class="muted">"수렴했다, 4.8%는 영구 유실" — <strong>오판</strong></td></tr>
          <tr class="sum"><td><code>+40h</code></td><td class="n">0</td><td><?= m_rate(543, 543) ?></td><td>전부 들어왔다</td></tr>
        </tbody>
      </table>
      <p class="caption"><strong>첫 줄만 분모가 다르다</strong> — +0h 에는 아직 508건까지만 보낸 상태였다. 분모를 최종 543 으로 맞춰 적으면 그 시점에 없던 전송까지 누락으로 세게 된다.</p>

      <p class="caption"><strong>감속은 수렴이 아니다.</strong> 유입이 1.10 → 0.10건/h 으로 떨어지는 것을 보고 25시간에 판정했는데,
        GA4 가 적어 둔 처리 창은 <strong>24~48시간</strong>이었다. 끝났는지는 <strong>값이 멈춘 것</strong>으로 판정해야지 느려진 것으로 판정하면 안 된다.
        <br>되읽기: <code>php public/index.php cli/verify ga4</code> — 2026-09-12 전송분 543건, 마지막 대조 <strong>2026-09-14 13:46</strong>(+40h).</p>
    </section>
  </div>

  <!-- ── 시트 4 · ② 전환 → 적재 ─────────────────── -->
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
        <table class="data data--tight">
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
        <table class="data data--tight">
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

  <!-- ── 시트 5 · ③ 매체 전송 ─────────────────────── -->
  <div class="sheet layout-metrics-3">
    <section class="panel reach" aria-labelledby="reach">
      <p class="caption caption--corner">
        <strong>반영률은 이 화면이 알 수 없다.</strong> 도달률은 매체가 <code>2xx</code> 를 돌려줬다는 것까지다.
        GA4 반영률은 <strong>+0h 1% · +25h 95% · +40h 100%</strong> — 문서에 남은 실측값이고 이 화면이 계산한 값이 아니다.
        <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 5-1<span aria-hidden="true">↗</span></a>
      </p>
      <p class="eyebrow" id="dispatch">③ 매체 전송</p>
      <h2 id="reach">도달률 <span class="muted">2xx / 시도</span></h2>
      <p class="meta-line">분모는 전송 시도 수. 무응답(타임아웃·커넥션 실패)은 실패로 센다 — 빼면 분모가 조용히 줄어 도달률이 오른다. 반영률은 이 표가 아니라 <a href="#reflected">매체를 되읽은 기록</a>에 있다 — <code>cli/verify ga4</code></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="도달률 표 — 가로로 스크롤">
        <table class="data data--tight">
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
        <table class="data data--tight">
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
        <table class="data data--tight">
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

  <!-- ── 시트 6 · ③ 계속 ──────────────────────────── -->
  <?php $segments = array('parse' => 'parse_ms', 'db' => 'db_ms', 'send' => 'send_ms', 'total' => 'total_ms'); ?>
  <div class="sheet layout-metrics-4">
    <section class="panel latency" aria-labelledby="latency">
      <p class="eyebrow">③ 매체 전송</p>
      <h2 id="latency">구간별 소요 시간 <span class="muted">ms · 평균 · p50 · p95 · 최대</span></h2>
      <p class="caption"><strong>평균만 보면 동시성의 대가가 안 보인다</strong> — 워커 1→4 에서 p95 43→83ms.
        구간 합이 <code>total</code> 근처여야 어디가 느린지 말할 수 있다. 표본이 얇으면 p95 는 <strong>가장 느린 값을 빼고</strong> 고른다 — 그래서 최대와 표본을 같이 낸다.
        <a href="<?= $repo ?>docs/benchmarks.md">benchmarks.md 2장 · 3-2<span aria-hidden="true">↗</span></a></p>
      <div class="table-scroll" tabindex="0" role="region" aria-label="구간별 소요 시간 표 — 가로로 스크롤">
        <table class="data data--tight">
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
        <table class="data data--tight">
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
