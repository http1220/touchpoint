<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 결제창 닫기(closeUrl). 이니시스가 결제창 안에서 부른다.
 *
 * [추정] 닫기 스크립트 주소는 공식 샘플에 있는데 샘플이 가맹점 로그인 뒤라
 * 확인하지 못했다. 09-19 운영 확인: 이 페이지가 불리고 결제창이 닫힌다.
 *
 * **상위 화면을 결과 화면으로 옮긴다(09-19 오후).** 닫기 스크립트보다 먼저 —
 * 결제 화면이 "결제창을 엽니다." 에 멈춰 닫았다는 반응이 없었다. 상위 화면이
 * 다른 오리진이라 막히면(이 페이지가 이니시스 프레임 안의 프레임이라면) 조용히
 * 넘어가고, 닫기 스크립트가 결제창만 닫는다 → controllers/Pay.php inicis_close()
 *
 * @var string      $close_js
 * @var string|null $result_url 닫은 결제의 결과 화면. uid 가 없으면 null
 */
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>결제창 닫기</title>
<meta name="robots" content="noindex, nofollow">
<?php if ($result_url !== NULL): ?>
<script>
  try { window.top.location.href = <?= json_encode($result_url) ?>; } catch (e) { /* 상위가 다른 오리진 — 결제창만 닫힌다 */ }
</script>
<?php endif; ?>
<script src="<?= html_escape($close_js) ?>" charset="UTF-8"></script>
</head>
<body>
<p>결제를 취소했습니다. 청구되지 않았습니다. <a href="<?= html_escape($result_url ?? '/pay') ?>" target="_top">결과 보기</a></p>
</body>
</html>
