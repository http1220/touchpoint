<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 오토로드는 최소로 둔다.
| 모든 요청이 지불하는 비용이므로, 전 경로가 쓰는 것만 넣는다.
*/

// database  — 세션 드라이버가 어차피 기본 그룹을 연다. 명시해 두는 편이 낫다.
// session   — 로그인·전환 판정이 세션에 붙는다.
$autoload['libraries'] = array('database', 'session');

$autoload['drivers']   = array();

// url    — site_url() · redirect()
// tp     — 이 프로젝트 공용 헬퍼 (application/helpers/tp_helper.php)
$autoload['helper']    = array('url', 'tp');

$autoload['config']    = array();
$autoload['language']  = array();
$autoload['model']     = array();
$autoload['packages']  = array();
