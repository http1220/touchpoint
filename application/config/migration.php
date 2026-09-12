<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| 마이그레이션.
|
| 웹에서 실행되지 않는다. CLI 컨트롤러(cli/migrate)로만 돌린다 —
| 브라우저 요청 하나로 스키마가 바뀌는 경로를 열어둘 이유가 없다.
| → application/controllers/Cli/Migrate.php
*/
$config['migration_enabled'] = TRUE;

// 순번(sequential)이 아니라 타임스탬프. 두 사람이 같은 날 마이그레이션을 만들면
// 순번은 충돌하고 타임스탬프는 충돌하지 않는다.
$config['migration_type'] = 'timestamp';

// 목표 버전. 새 마이그레이션을 추가하면 이 값을 올린다.
// 이 값을 올리는 것을 잊으면 latest 가 새 파일을 건너뛴다 — 흔한 함정이다.
$config['migration_version'] = 20260912000100;

$config['migration_table']    = 'ci_migrations';
$config['migration_auto_latest'] = FALSE;   // 요청마다 마이그레이션이 도는 일은 없어야 한다
$config['migration_path']     = APPPATH.'migrations/';
