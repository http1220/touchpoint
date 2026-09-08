<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| touchpoint · CodeIgniter 3 설정
|
| 기본 스켈레톤을 그대로 두지 않고 이 프로젝트가 실제로 쓰는 키만 남겼다.
| 값을 바꾼 곳에는 이유를 적는다. 이유 없는 설정은 나중에 아무도 못 건드린다.
| 구조 근거: docs/decisions/ADR-017-ci3-application-structure.md
*/

// 빈 값이면 CI3가 요청 호스트에서 추론한다.
// 이 프로젝트는 호스트가 5개(루트·lp·m·app·api)라 고정할 수 없다.
$config['base_url'] = '';

// index.php 를 URL에서 감춘다. nginx의 try_files 가 받아준다.
// → docker/openresty/conf.d/php-app.conf
$config['index_page'] = '';

$config['uri_protocol'] = 'REQUEST_URI';
$config['url_suffix']   = '';

$config['language'] = 'english';
$config['charset']  = 'UTF-8';

$config['enable_hooks']    = FALSE;
$config['subclass_prefix'] = 'MY_';

/*
| PSR-4 병용의 핵심 한 줄.
|
| 이 줄이 있어야 application/ 안에서 App\... 네임스페이스가 보인다.
| 컨트롤러·모델은 CI3 관용구로, 도메인 로직은 src/ 의 PSR-4로 간다.
| 경계를 나눈 이유는 ADR-017 참조.
*/
$config['composer_autoload'] = FCPATH.'../vendor/autoload.php';

// 기본값에 없는 문자를 추가로 허용하지 않는다.
$config['permitted_uri_chars'] = 'a-z 0-9~%.:_\-';

$config['allow_get_array']     = TRUE;   // 수집 엔드포인트가 쿼리스트링을 쓴다
$config['enable_query_strings'] = FALSE;
$config['controller_trigger']  = 'c';
$config['function_trigger']    = 'm';
$config['directory_trigger']   = 'd';

/*
| 로그.
|
| 파일이 아니라 stderr 로 보낸다 — 컨테이너 로그가 곧 애플리케이션 로그다.
| 12-Factor 의 로그 규칙이고, docker logs 하나로 전 계층을 볼 수 있게 된다.
| CI3 는 기본적으로 application/logs/ 에 파일을 쓰는데, 바인드 마운트에서는
| 권한이 없으면 예외도 없이 로깅이 꺼진다. 그래서 application/core/MY_Log.php 가
| write_log() 를 덮어 JSON 한 줄을 stderr 로 보낸다.
*/
$config['log_threshold']       = (ENVIRONMENT === 'production') ? 1 : 4;
// MY_Log 가 stderr 로 보내므로 이 값은 쓰이지 않는다.
// 남겨 두는 이유는 MY_Log 를 지웠을 때 기본 동작으로 돌아가게 하기 위함이다.
$config['log_path']            = '';
$config['log_file_extension']  = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format']     = 'Y-m-d H:i:s.v';   // 밀리초. 지연 추적에 필요하다

$config['error_views_path'] = '';
$config['cache_path']       = '';
$config['cache_query_string'] = FALSE;

// openssl rand -hex 16 으로 만들어 .env 에 넣는다. 비면 세션/암호화가 성립하지 않는다.
$config['encryption_key'] = getenv('ENCRYPTION_KEY') ?: '';

/*
| 세션 — database 드라이버, 그리고 반드시 프라이머리.
|
| 세션 테이블 읽기가 복제본으로 가면 로그인 직후 세션이 풀린다.
| CI3의 database 세션 드라이버는 기본 DB 그룹을 쓰므로,
| database.php 의 $active_group 을 'write' 로 두는 것이 이 보장의 전부다.
| → ADR-017 ④
*/
$config['sess_driver']             = 'database';
$config['sess_cookie_name']        = 'tp_sess';
$config['sess_expiration']         = 7200;
$config['sess_save_path']          = 'ci_sessions';
$config['sess_match_ip']           = FALSE;   // 모바일 캐리어 IP는 요청마다 바뀐다
$config['sess_time_to_update']     = 300;
$config['sess_regenerate_destroy'] = FALSE;   // 동시 요청 중 재생성되면 유실된다

/*
| 쿠키.
|
| 여기 값은 docs/domains-and-cookies.md 3장의 정책표와 일치해야 한다.
| 수집 쿠키(SameSite=None; Secure; Partitioned)는 이 설정으로 못 만든다 —
| CI3의 set_cookie 는 Partitioned 를 모른다. 그건 애플리케이션이 직접 헤더로 붙인다.
*/
$config['cookie_prefix']   = '';
$config['cookie_domain']   = getenv('SHOP_DOMAIN') ? '.'.getenv('SHOP_DOMAIN') : '';
$config['cookie_path']     = '/';
$config['cookie_secure']   = TRUE;
$config['cookie_httponly'] = TRUE;
$config['cookie_samesite'] = 'Lax';

$config['standardize_newlines'] = FALSE;

/*
| global_xss_filtering 는 켜지 않는다.
|
| 입력을 통째로 변형하면 utm_content 같은 원문 보존이 필요한 값이 조용히 망가진다.
| 방어는 출력 시점(html_escape)과 바인딩된 쿼리로 한다. 입력 시점이 아니다.
*/
$config['global_xss_filtering'] = FALSE;

/*
| CSRF.
|
| 폼(가입·결제)에는 필요하고, 수집 엔드포인트에는 성립하지 않는다 —
| 광고 랜딩에서 다른 등록 도메인으로 보내는 요청이라 토큰을 심을 수 없다.
| 그래서 수집 경로만 제외하고, 그 경로의 방어는 Origin 검사로 따로 한다.
| → docs/api-spec.md
*/
$config['csrf_protection']   = TRUE;
$config['csrf_token_name']   = 'tp_csrf_token';
$config['csrf_cookie_name']  = 'tp_csrf';
$config['csrf_expire']       = 7200;
$config['csrf_regenerate']   = FALSE;   // 탭 여러 개를 열면 토큰이 어긋난다
$config['csrf_exclude_uris'] = array(
	'collect',
	'conversion',
	'impression',
	'click',
	'webhook/.*',
);

// 압축은 nginx 가 한다. PHP에서 또 하면 이중 처리다.
$config['compress_output'] = FALSE;

$config['time_reference']     = 'UTC';   // 저장·연산은 전부 UTC
$config['rewrite_short_tags'] = FALSE;

/*
| 프록시.
|
| app 컨테이너 앞에 OpenResty 가 있다. 이 값을 비워두면
| $this->input->ip_address() 가 항상 엣지의 IP를 돌려준다 —
| 국가 판별과 어뷰징 탐지가 통째로 무의미해지는 지점이다.
| 172.16/12 는 도커 기본 브리지 대역.
*/
$config['proxy_ips'] = '172.16.0.0/12';
