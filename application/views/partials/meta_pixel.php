<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Meta 픽셀 스니펫. **기본은 꺼져 있다** — META_PIXEL_BROWSER=true 일 때만.
|
| 넣는 이유는 gtag 와 같은 구조다.
|
|   ① `_fbp` 쿠키 발급 — 서버 전환(CAPI)이 사람과 이어지는 가장 강한
|      단서다. 이 쿠키가 없으면 매칭은 IP·UA·외부 ID 에만 기댄다
|      → docs/decisions/ADR-005-channel-adapter.md 「결정」
|   ② PageView — 브라우저가 보낸다
|
| 역할 분담도 gtag 와 같다.
|
|   픽셀  PageView                   (브라우저)
|   서버  Purchase 같은 전환          (아웃박스 → 워커 → CAPI)
|
| **전환은 서버만 보낸다.** 픽셀에서도 Purchase 를 쏘면 event_id 로 합쳐야
| 하는데, 결제 확정은 웹훅에서 일어나 브라우저가 그 시점의 conversion_uid 를
| 모른다. 합칠 키가 없는 이중 전송이 된다.
|
| 켜기 전에 필요한 것 — 코드가 아니라 고지다.
|   · 개인정보처리방침: 행태정보 수집, Meta(국외) 제공 → /privacy (09-15)
|   · 거부 수단 — 거부한 브라우저에는 이 스니펫을 내보내지 않는다
|   · EU 방문자가 있으면 동의 전에는 켜지 않는다
| 그래서 기본값을 끔으로 두고, 켜는 일은 설정 한 줄로 남긴다.
*/

$pixel_id  = getenv('META_PIXEL_ID') ?: '';
$opted_out = \App\Attribution\AdOptOut::isOn(get_instance()->input->cookie(\App\Attribution\AdOptOut::COOKIE, TRUE));

if ( ! tp_env_bool('META_PIXEL_BROWSER') OR $opted_out OR preg_match('/\A\d{10,20}\z/', $pixel_id) !== 1):
	return;
endif;
?>
<script>
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
  document,'script','https://connect.facebook.net/en_US/fbevents.js');

  fbq('init', '<?= html_escape($pixel_id) ?>');
  fbq('track', 'PageView');
</script>
