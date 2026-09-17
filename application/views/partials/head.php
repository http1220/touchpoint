<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| <head> 공통부. 화면은 제목만 넘긴다.
|
| 스타일은 public/assets/site.css 한 장이다. 호스트마다 같은 public/ 을 루트로
| 쓰므로 상대경로로 읽는다 — 다른 오리진으로 요청이 나가지 않는다.
|
| ?v= 는 파일 수정 시각이다. 배포가 git pull 뿐이라 이름을 바꾸는 빌드 단계가 없고,
| 이게 없으면 브라우저가 옛 CSS 로 새 마크업을 그린다.
|
| @var string $title
*/
$css = FCPATH.'assets/site.css';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= html_escape($title) ?></title>
<link rel="stylesheet" href="/assets/site.css?v=<?= is_file($css) ? (int) filemtime($css) : 0 ?>">
