<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| touchpoint · DB 커넥션 그룹
|
| 그룹이 셋이다. 이유는 하나 — 읽기가 어디로 가느냐를 요청마다 다르게 하려고.
|
|   write      프라이머리. 쓰기, 쓰기 직후 읽기, 그리고 세션
|   read_rdb1  프라이머리를 읽기로 사용. 복제 지연 0
|   read_rdb2  실제 복제본. 여기서만 지연이 드러난다
|
| 어느 read 그룹을 쓸지는 OpenResty 의 Lua 가 쿠키로 배정해
| AB_READ_TARGET FastCGI 파라미터로 넘긴다.
| → docker/openresty/lua/replica.lua · application/core/MY_Controller.php
| → docs/decisions/ADR-007-read-write-split.md, ADR-017 ③
*/

$db_common = array(
	'dsn'      => '',
	'username' => getenv('MYSQL_USER') ?: 'ab',
	'password' => getenv('MYSQL_PASSWORD') ?: '',
	'database' => getenv('MYSQL_DATABASE') ?: 'attribution',
	'port'     => (int) (getenv('DB_PORT') ?: 3306),
	'dbdriver' => 'mysqli',
	'dbprefix' => '',
	'pconnect' => FALSE,

	// 운영에서도 켠다. 끄면 실패한 쿼리가 조용히 FALSE 를 돌려주고
	// 그 FALSE 가 훨씬 뒤에서 엉뚱한 증상으로 터진다.
	'db_debug' => TRUE,

	'cache_on'   => FALSE,
	'cachedir'   => '',
	'char_set'   => 'utf8mb4',
	'dbcollat'   => 'utf8mb4_0900_ai_ci',   // MySQL 의 'utf8' 은 3바이트 — 이모지가 깨진다
	'swap_pre'   => '',
	'encrypt'    => FALSE,
	'compress'   => FALSE,
	'stricton'   => TRUE,                   // STRICT_ALL_TABLES. 잘림을 경고가 아니라 에러로
	'failover'   => array(),
	'save_queries' => (ENVIRONMENT !== 'production'),
);

/*
| 기본 그룹은 반드시 write 다.
|
| CI3 의 database 세션 드라이버는 기본 그룹을 쓴다.
| 여기를 read 로 두는 순간 세션 조회가 복제본으로 가고,
| 로그인 직후 세션이 아직 복제되지 않아 로그인이 풀린다.
| 읽기 분리를 도입할 때 가장 먼저 터지는 자리다. → ADR-017 ④
*/
$active_group = 'write';
$query_builder = TRUE;

$db['write'] = array_merge($db_common, array(
	'hostname' => getenv('DB_WRITE_HOST') ?: 'mysql-primary',
));

$db['read_rdb1'] = array_merge($db_common, array(
	'hostname' => getenv('DB_READ_RDB1') ?: 'mysql-primary',
));

$db['read_rdb2'] = array_merge($db_common, array(
	'hostname' => getenv('DB_READ_RDB2') ?: 'mysql-replica',
));
