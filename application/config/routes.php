<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 라우팅.
|
| 호스트 5개(루트·lp·m·app·api)가 같은 애플리케이션에 들어온다.
| CI3 에는 호스트 기반 라우팅이 없으므로 경로만 여기서 매핑하고,
| "이 경로가 이 호스트에서 열려도 되는가"는 컨트롤러가 판정한다.
| → application/core/MY_Controller.php 의 requireHost()
|
| 경로 표는 docs/api-spec.md 의 엔드포인트 목록과 1:1로 맞춘다.
*/

$route['default_controller'] = 'home';   // 루트 도메인의 웹툰 홈. lp. 는 /l/{work} 로 들어온다
$route['tour']        = 'tour/index';    // 시연 안내. 홈은 서비스로 두고 설명은 여기 — 루트 도메인
$route['404_override']       = 'errors/not_found';

// URL 끝의 슬래시를 컨트롤러 이름으로 오해하지 않게 한다.
$route['translate_uri_dashes'] = FALSE;

// ── lp. 광고주 랜딩 · 브리지 ────────────────────────────
$route['go']          = 'bridge/go';         // 1. GET  /go
$route['l/(:any)']    = 'landing/work/$1';   // 2. GET  /l/{work}

// ── api. 수집 (다른 등록 도메인) ────────────────────────
$route['collect']     = 'collect/index';     // 3. POST /collect
$route['conversion']  = 'conversion/index';  // 4. POST /conversion
$route['impression']  = 'collect/impression';// 커스텀 수집 ①
$route['click']       = 'collect/click';     // 커스텀 수집 ②
$route['track\.js']   = 'track/js';          // 서드파티 스크립트 — ADR-017 ⑤

// ── app. 서비스 · 전환 ──────────────────────────────────
$route['signup']      = 'account/signup';    // 5. POST /signup
$route['purchase']    = 'purchase/index';    // 6. POST /purchase
$route['webhooks/pg'] = 'webhook/pg';        // PG 웹훅. app. 에서만 — docs/plan-payment-webhook.md 12장 결정 1

// 실PG(이니시스) — 입장권(안내 화면의 버튼으로 누구나 받는다)이 있어야 열린다. docs/plan-multi-pg.md
$route['pay']                = 'pay/index';          // GET  시연 결제 화면 (입장권 없으면 받기 안내)
$route['pay/access']         = 'pay/access';         // POST 입장권 발급 — /tour 의 버튼
$route['pay/stub/confirm']   = 'pay/stub_confirm';   // POST 카드 없는 한 바퀴 — 가짜 PG 가 확정 웹훅을 두 번
$route['pay/inicis/start']   = 'pay/inicis_start';   // POST 결제창 필드 서명
$route['pay/inicis/return']  = 'pay/inicis_return';  // POST 이니시스 복귀 → 승인 → 장부
$route['pay/inicis/close']   = 'pay/inicis_close';   // *    결제창 닫기
$route['pay/result/(:any)']  = 'pay/result/$1';      // GET  결과
$route['metrics']     = 'metrics/index';     // 7. GET  /metrics
$route['privacy']     = 'privacy/index';     // 개인정보처리방침 — 픽셀보다 먼저
$route['privacy/ads'] = 'privacy/ads';       // 맞춤형 광고 거부 (쿠키)
$route['episode/(:num)'] = 'episode/view/$1';

// ── 운영 ────────────────────────────────────────────────
// 헬스체크는 DB를 건드리지 않는다. DB가 죽었을 때도 "프로세스는 살아있다"를
// 구분해서 알려줘야 장애 원인을 좁힐 수 있다.
$route['healthz']     = 'health/live';
$route['readyz']      = 'health/ready';      // 이쪽은 DB까지 확인한다
$route['diag']        = 'diag/index';        // 인프라 진단 (개발 환경 전용)

/*
| CLI 전용.
|
| is_cli() 로 컨트롤러가 스스로 막지만, 라우팅 단계에서 한 번 더 막는다.
| 방어는 겹쳐야 한다 — 컨트롤러 하나를 리팩터링하다 검사를 빼먹어도
| 워커 진입점이 웹에 노출되지는 않는다.
*/
if ( ! is_cli())
{
	$route['cli/(:any)'] = 'errors/not_found';
}
