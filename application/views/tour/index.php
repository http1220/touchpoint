<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 시연 안내. 홈에서 옮겨 왔다.
 *
 * 흐름도는 SVG 가 아니라 글자와 선으로 그린다 — 좁은 화면에서 글자가 줄어들지 않고,
 * 스크린리더가 순서를 목록으로 읽는다. 화살표는 CSS 가 그리고 보조기술에서는 숨긴다.
 *
 * @var string $trace_id
 */
$repo = 'https://github.com/http1220/touchpoint/blob/main/';

$go = tp_host_url('lp', '/go?work=1&pid=tour&utm_source=tour&utm_medium=internal');

/** 파이프라인 다섯 걸음 — 링크가 있는 걸음은 실제로 그 화면으로 간다 */
$flow = array(
	array('lane' => '브라우저', 'name' => '광고 클릭', 'href' => $go,
	      'sub'  => '유입 파라미터를 달고 <code>/go</code> 로. 눌러 보세요'),
	array('lane' => '서버', 'name' => '브리지 302', 'href' => NULL,
	      'sub'  => '여기서 최초·마지막 유입이 남는다. 랜딩에는 방문 번호만 넘긴다'),
	array('lane' => '브라우저', 'name' => '작품 랜딩', 'href' => tp_host_url('lp', '/l/1'),
	      'sub'  => '무엇이 기록됐는지 화면이 보여 준다. <code>track.js</code> 가 노출·클릭을 모은다'),
	array('lane' => '서버', 'name' => '전환 → 아웃박스', 'href' => NULL,
	      'sub'  => '전환과 보낼 것을 <strong>같은 트랜잭션</strong>에 적는다'),
	array('lane' => '매체', 'name' => '워커 → GA4·Meta', 'href' => tp_host_url('app', '/metrics'),
	      'sub'  => '도달률은 이 화면이, 반영률은 매체를 되읽어야 안다'),
);
?><!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('partials/head', array('title' => '시연 안내 · touchpoint')); ?>
</head>
<body>
<main class="stage">
  <div class="sheet layout-tour">
    <?php $this->load->view('partials/demo_bar', array('demo_step' => 0)); ?>

    <header class="masthead">
      <p class="masthead__mark"><a href="<?= html_escape(tp_host_url('root', '/')) ?>">touchpoint</a> <span class="masthead__sub">시연 안내</span></p>
      <p class="eyebrow masthead__note">광고 클릭에서 매체 보고서까지</p>
    </header>

    <section class="panel tour-intro" aria-labelledby="page-title">
      <h1 id="page-title">광고 클릭에서 GA4 보고서까지 실제로 돌려 봤고, 숫자는 <span class="nowrap">분모·표본·측정 시각과</span> 함께만 보여 줍니다.</h1>
      <p class="caption caption--corner">
        홈은 <strong>측정할 대상</strong>으로 만든 웹툰 서비스 모형입니다. 작품·회차는 실제 DB 이고, 표지는 자리표시입니다.
      </p>

      <ol class="flow">
        <?php foreach ($flow as $s): ?>
          <li class="flow__step">
            <?php if ($s['href'] !== NULL): ?>
              <a class="flow__box" href="<?= html_escape($s['href']) ?>">
                <span class="flow__lane"><?= html_escape($s['lane']) ?></span>
                <span class="flow__name"><?= html_escape($s['name']) ?></span>
                <span class="flow__sub"><?= $s['sub'] ?></span>
              </a>
            <?php else: ?>
              <div class="flow__box">
                <span class="flow__lane"><?= html_escape($s['lane']) ?></span>
                <span class="flow__name"><?= html_escape($s['name']) ?></span>
                <span class="flow__sub"><?= $s['sub'] ?></span>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>

      <p class="meta-line">밑줄 친 세 칸은 실제로 그 화면으로 갑니다. 가운데 둘은 화면이 없는 서버 쪽이라, 그 결과를 랜딩과 지표에서 봅니다.</p>
    </section>

    <section class="panel tour-model" aria-labelledby="model-title">
      <h2 id="model-title">홈이 말하지 않는 것 — 데이터 모델</h2>
      <p class="meta-line">홈은 서비스처럼 보이게 두었습니다. 그 화면이 선 사실은 여기 적습니다.</p>
      <div class="captions">
        <p class="caption">작품 하나가 <strong>여러 요일</strong>에 연재됩니다 — 그래서 연재 요일은 별도 테이블입니다.
          <a href="<?= $repo ?>docs/data-model.md">data-model.md 6장<span aria-hidden="true">↗</span></a></p>
        <p class="caption"><strong>N시간 후 무료</strong>는 작품의 속성이고, 무료·유료는 회차의 속성입니다. 연령은 참/거짓이 아니라 코드입니다.</p>
        <p class="caption">언어·문자·지역은 <strong>다른 축</strong>입니다 — 간체와 번체는 둘 다 <code>zh</code> 이고 문자로 갈립니다. 언어를 바꾸면 작품 데이터만 바뀝니다.</p>
        <p class="caption">순위는 조회수나 평점이 아니라 <strong>회차 수</strong>로 세웁니다 — 조사한 플랫폼 모두 조회수를 공개하지 않았고, 없는 지표를 지어내지 않습니다.
          <a href="<?= $repo ?>docs/research-method.md">research-method.md 3-1<span aria-hidden="true">↗</span></a></p>
      </div>
    </section>
  </div>
</main>

<?php $this->load->view('partials/footer'); ?>
</body>
</html>
