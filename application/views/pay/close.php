<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 결제창 닫기(closeUrl). 이니시스 결제창 안에서 불린다.
 *
 * [추정] 닫기 스크립트 주소는 공식 샘플에 있는데 샘플이 가맹점 로그인 뒤라
 * 확인하지 못했다. 무과금 확인 ①에서 판정한다 → controllers/Pay.php inicis_close()
 *
 * @var string $close_js
 */
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>결제창 닫기</title>
<meta name="robots" content="noindex, nofollow">
<script src="<?= html_escape($close_js) ?>" charset="UTF-8"></script>
</head>
<body>
<p>결제를 취소했습니다. 청구되지 않았습니다. <a href="/pay" target="_top">결제 화면으로</a></p>
</body>
</html>
