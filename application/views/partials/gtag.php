<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| GA4 gtag.js 스니펫.
|
| 측정 ID 가 비어 있으면 아무것도 출력하지 않는다. 로컬이나 CI 에서
| 남의 속성으로 데이터가 새는 것을 막는다.
|
| 이 태그를 넣는 이유가 둘이다.
|
|   ① 클라이언트 측 수집 — GA4 가 페이지뷰·세션을 스스로 잡는다
|   ② **_ga 쿠키 발급** — 서버가 Measurement Protocol 로 보낼 때 필요한
|      client_id 가 이 쿠키에 들어 있다. 이 태그가 없으면 서버 전송이
|      사용자를 이어 붙이지 못하고 매번 신규 사용자로 잡힌다
|      → docs/outbox-and-channels.md 7장
|
| 역할 분담을 지킨다.
|
|   gtag  page_view · 세션 · 참여도       (브라우저가 보낸다)
|   서버  purchase · sign_up 같은 전환    (아웃박스 → 워커 → MP)
|
| 전환을 양쪽에서 보내면 GA4 에 두 번 잡힌다. transaction_id 로 합쳐지긴
| 하지만 합쳐지는 것과 안 보내는 것은 다르다 — **전환은 서버만 보낸다.**
*/

$ga4_id = getenv('GA4_MEASUREMENT_ID') ?: '';

if ($ga4_id === '' OR preg_match('/\AG-[A-Z0-9]{4,20}\z/', $ga4_id) !== 1):
	return;
endif;
?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= html_escape($ga4_id) ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', '<?= html_escape($ga4_id) ?>', {
    /* 쿠키를 등록 도메인에 건다.
       기본값 'auto' 도 보통 .sshwan.com 에 걸지만, 명시해 두면
       lp. 에서 만든 _ga 를 api. 의 서버가 읽는다는 것이 설정으로 남는다. */
    cookie_domain: '<?= html_escape(getenv('SHOP_DOMAIN') ?: 'auto') ?>',

    /* IP 를 익명화한다. 어트리뷰션에 원본 IP 가 필요 없다 —
       우리 DB 도 ip_hash 만 둔다. */
    anonymize_ip: true
  });
</script>
