# 작업 기록

> 날짜순으로 **한 일 · 막힌 것 · 결정한 것**을 남긴다. 최신이 위.

## 왜 이걸 쓰는가

세 가지 용도가 있고, 셋 다 나중에 값을 한다.

| 용도 | 설명 |
|---|---|
| **실패 문서의 원재료** | 막힌 순간의 증상과 에러 원문은 그때 적어두지 않으면 복원되지 않는다. [failure-scenarios.md](failure-scenarios.md)의 `결과` 칸은 여기서 옮겨 적는다 |
| **계측의 맥락** | "이 수치를 잴 때 무엇이 달랐는가"는 숫자 옆에 없으면 사라진다 |
| **면접 답변** | "어떻게 진행하셨나요"에 날짜와 사건으로 답할 수 있게 된다. 결과만 아는 사람과 과정을 아는 사람은 다르게 들린다 |

## 쓰는 규칙

- **막힌 것을 먼저 적는다.** 잘 된 건 커밋 로그에 남지만 막힌 건 아무 데도 안 남는다
- 에러 메시지는 **원문 그대로** 붙인다. 요약하면 검색이 안 된다
- 추측과 확인을 구분한다 — `[추정]` / `[확인]`
- 하루가 비어도 빈 채로 둔다. 채워 넣지 않는다

---

## 2026-09-11 (금) · D-10

### 한 일

로드맵 **M2 ①②** — 광고 클릭이 방문과 접점으로 남는 경로를 끝에서 끝까지 연결했다.

```
GET /go?work=8733&pid=Google&utm_source=Google&gclid=..
  → 방문 확인/생성 · ab_vid 발급
  → 접점 판정 (first 보존 · last 갱신)
  → 302 → /l/8733?vid=..
```

- `src/` (프레임워크 비의존, 테스트 있음)
  - `BridgeDestination` — 목적지 URL. `vid` / `passthru` 두 방식
  - `AcceptLanguage` — 헤더 → ISO 639-1 두 글자
- `application/` (CI3 관용구)
  - `Visit_model` · `Visitor` · `Bridge` · `Landing`
- 랜딩 화면에 기록된 first/last 를 그대로 노출 — 확인하려고 DB 를 열지 않게

기본값을 `vid` 로 둔 이유는 **공유**다. `passthru` 로 보내면 사용자가 주소창을 복사해 보내는 순간 그 방문이 원래 사용자의 광고 클릭으로 집계된다. 유입 하나가 여러 건으로 불어나고 매체 정산이 틀어진다.

### 막힌 것

**① `/go` 가 404, 로그는 어디에도 없었다**

- **증상**: nginx 로그에도 CI3 로그에도 아무것도 안 남고 404 만
- **원인** [확인]: `php-pass.conf` 가 `SCRIPT_FILENAME` 을 `$document_root$fastcgi_script_name` 으로 만든다. `/go` 는 실제 파일이 아니므로 `/var/www/html/public/go` 를 가리키고 PHP-FPM 이 "File not found" 로 404 를 낸다. **프론트 컨트롤러를 아예 거치지 않으니** CI3 로그가 남을 리가 없었다
- **아이러니**: `no-store` 를 붙이려고 만든 `location /go` 가 정작 그 응답을 못 만들게 하고 있었다
- **대응**: 지우고 `try_files` 에 맡긴다. 캐시 정책은 그 응답이 무엇인지 아는 쪽(앱)이 정한다. `api.` 의 `/collect` `/conversion` `/impression` `/click` 도 같은 모양이라 같이 지웠다 — **아직 호출해 본 적이 없어 드러나지 않았을 뿐이다**

**② BINARY(16) 을 이스케이프 끄고 넣고 있었다** — 이번 최대 실수

```
Error Number: 1064
... near '??? ?s??ǫ????  LIMIT 1'
SELECT `id` FROM `visits` WHERE visit_uid =  ��� �s��ǫ����  LIMIT 1
```

- **원인** [확인]: `->where('visit_uid', hex2bin($uidHex), FALSE)`. 세 번째 인자 `FALSE` 는 이스케이프를 끈다. 바이너리 16바이트가 SQL 문자열에 그대로 박혔다
- **더 중요한 것**: 이건 구문 오류로 끝난 게 **운이 좋았던 것**이다. 값이 쿠키에서 오고, 바이트를 고르면 쿼리를 조작할 수 있는 자리였다. 500 이 안 났으면 그대로 배포됐다
- **대응**: `UNHEX(?)` 에 hex 문자열 바인딩. SQL 로 나가는 것은 32자 hex 뿐이고 변환은 MySQL 이 한다. `create()` 도 같은 방식으로 맞췄다 — 같은 컬럼을 두 방식으로 다루면 다음 사람이 어느 쪽이 맞는지 알 수 없다
- **배운 것**: **"이스케이프를 끈다"는 옵션이 있으면 언젠가 쓰게 된다.** 바이너리를 넣으려다 껐는데, 애초에 바이너리를 SQL 에 넣지 않는 방법(`UNHEX`)이 있었다. 옵션을 끄기 전에 그 옵션이 필요 없는 방법부터 찾는다

**③ CLI 로 재현할 수 없었다**

- `requireHost()` 가 CLI 를 404 로 막고 있었다. CLI 에는 `HTTP_HOST` 가 없다
- 그 바람에 ② 를 CLI 로 재현하지 못하고 개발 환경으로 잠깐 전환해서야 원문을 봤다
- **대응**: `is_cli()` 면 통과. 진단 경로를 막는 방어는 방어가 아니다

### 확인한 동작 (운영, production)

| 시험 | 결과 |
|---|---|
| `pid=Google` → 저장 | `google` (집계 축은 소문자 정규화) |
| `utm_campaign=romance_sep` | 원문 보존 (자유 텍스트) |
| 파라미터 없이 재방문 | first `kept` · last `kept` — **직접 유입이 광고 성과를 덮지 않는다** |
| 다른 매체로 재유입 | first 는 `google` 유지, last 만 `meta` |
| `mode=passthru` | 화이트리스트만 전달. `work`·`mode`·`ref` 탈락 |
| `work=abc` / `work=https://evil.example` | 400 |
| UTF-8 파라미터 | `%EB%84%A4%EC%9D%B4%EB%B2%84` 그대로 통과 |
| `app.sshwan.com/go` | 404 (호스트 검증 동작) |
| `m.sshwan.com/go` | 302 (모바일 허용) |

### 결정한 것

| 결정 | 근거 |
|---|---|
| 가상 경로에 **별도 location 을 두지 않는다** | `SCRIPT_FILENAME` 이 없는 파일을 가리켜 프론트 컨트롤러를 건너뛴다. 캐시·CORS 헤더는 앱이 붙인다 |
| BINARY 컬럼은 **`UNHEX(?)` 바인딩으로만** | 이스케이프를 끄는 경로를 코드에서 없앤다 |
| 랜딩에 접점을 **화면으로 노출** | 확인 비용이 낮아야 실험을 자주 한다. 면접에서도 이 화면 하나로 규칙을 설명할 수 있다 |
| `requireHost` 는 **CLI 를 통과** | 진단 경로를 막으면 재현이 불가능해진다 |

### 다음

- [ ] 추적 도메인 확보(AWS 외 등록기관) → `api.` 붙이고 크로스사이트 실험(M3)
- [ ] `track.js` — `fetch` 와 `sendBeacon` 두 경로
- [ ] 커스텀 수집 `/impression` · `/click`
- [ ] 아웃박스 + GA4 채널

---

## 2026-09-09 (수) · D-12

### 한 일

- `feat/ci3-skeleton` → `main` 병합. 리서치·설계·인프라·도메인 로직이 한 덩어리로 `main`에 올라감
- **GitHub Actions CI** 추가 — 문법 검사 · 단위 테스트 · 경계 검사 · compose 유효성 · OpenResty 문법
- **CodeIgniter 3 스켈레톤** 구성 — composer 로 `codeigniter/framework:3.1.13` 설치, `public/index.php` 를 프론트 컨트롤러로
- **스키마 마이그레이션 7개** 작성하고 실제 MySQL 8.0 에 up → down → up 왕복 검증 (테이블 18개)
- `MY_Controller` — 읽기 커넥션 배정, 상관 ID, 호스트 검증, RFC 9457 에러 응답
- 진단 페이지를 `public/index.php` 에서 `Diag` 컨트롤러로 이관
- EC2 t3.small 생성 절차와 비용 방어 장치를 [setup.md](setup.md) 2·2-1장에 정리
- **도메인 1개로도 기동되도록 분리** — 추적 도메인 등록이 AWS 쪽 문제로 막혀, `api.` 서버 블록을 조건부 생성으로 뗌
- EC2 t3.small 생성 절차와 비용 방어 장치를 [setup.md](setup.md) 2·2-1장에 정리

### 막힌 것

**① CI3 를 PHP 8.2 에서 돌리니 요청마다 deprecation 이 쏟아졌다** — [ADR-001](decisions/ADR-001-php-codeigniter.md)이 예고한 마찰의 첫 실물

```
PHP Deprecated:  Creation of dynamic property CI_URI::$config is deprecated
                 in .../system/core/URI.php on line 102
PHP Deprecated:  Creation of dynamic property CI_Router::$uri is deprecated
                 in .../system/core/Router.php on line 128
PHP Deprecated:  Creation of dynamic property CI_DB_mysqli_driver::$failover is deprecated
                 in .../system/database/DB_driver.php on line 372
PHP Deprecated:  Creation of dynamic property Migrate::$migration is deprecated
                 in .../system/core/Loader.php on line 1284
```

- **원인** [확인]: CI3 의 로딩 방식 자체가 동적 프로퍼티다. `$this->load->library('x')` 가 `$this->x` 를 런타임에 만들어 붙인다. 8.2 에서 이건 deprecated 다. **프레임워크 내부(`vendor/`)에서 나는 것과 우리 컨트롤러에서 나는 것 두 종류**가 섞여 있었다
- **대응**: 두 종류를 다르게 처리했다
  - 우리 컨트롤러 쪽 — `MY_Controller` 에 `#[\AllowDynamicProperties]`. 상속되므로 한 곳으로 끝난다
  - 프레임워크 내부 쪽 — `MY_Exceptions` 로 `log_exception()`·`show_php_error()` 를 감싸, **경로가 `vendor/codeigniter/framework/` 인 E_DEPRECATED 만** 걸러낸다
- **배운 것**: 처음에는 `error_reporting` 에서 `E_DEPRECATED` 를 빼려고 했다. 그러면 **우리가 새로 쓴 코드의 deprecation 까지 같이 사라진다** — 8.2 위에서 CI3 를 돌리며 배우려던 것을 스스로 지우는 짓이다. 억제 조건을 "발생 위치"로 좁히니 우리 코드의 경고는 그대로 보인다. 그리고 억제한 건수는 세어서 `/diag` 에 띄운다. **안 보이게 하는 것과 없는 것처럼 구는 것은 다르다**

**② `migration_table` 이 `NULL` 이었다**

```
Database error: Error Number: 1096  No tables used
SELECT *
```

- **원인** [확인]: `$this->config->item('migration_table')` 이 `NULL`. CI3 의 Migration 라이브러리는 `config/migration.php` 를 **자기 안으로만** 가져가고 `$this->config` 에는 올리지 않는다. `$this->db->get(NULL)` 이 되어 `SELECT *` 만 나갔다
- **대응**: 컨트롤러에서 `$this->config->load('migration', FALSE, TRUE)` 를 한 번 더 호출. 하드코딩하지 않은 이유는 설정을 바꿨을 때 조용히 어긋나기 때문
- **배운 것**: CI3 의 "라이브러리가 설정을 먹는다"와 "설정이 전역에 올라간다"는 다른 일이다. 라이브러리 생성자에 `$config` 가 넘어가는 프레임워크에서는 매번 확인해야 한다

**③ 인덱스 실험은 아직 성립하지 않는다**

- `EXPLAIN` 을 인덱스 있음/없음 양쪽에서 떠 봤으나 **양쪽 다 `key: PRIMARY`** 였다
- **원인** [확인]: 테이블이 비어 있다(`rows: 1`). 옵티마이저가 `ORDER BY id` 만 보고 PK 를 골랐다. 인덱스의 값어치는 **데이터가 있어야** 드러난다
- **대응**: 인덱스 추가를 별도 마이그레이션(`20260909000700`)으로 떼어 두었으므로, 적재 후 `to 20260909000600` ↔ `latest` 로 왕복하며 다시 측정한다 → [benchmarks.md](benchmarks.md)


**④ 엣지 컨테이너가 애초에 뜰 수 없는 상태였다** — 기동 검증을 미룬 대가

```
/entrypoint.sh: line 21: envsubst: not found
```

- **증상**: `${SHOP_DOMAIN}` 치환에 `envsubst` 를 쓰고 있었는데, `openresty/openresty:alpine` 에 gettext 가 없다. **설계한 날부터 지금까지 이 구성으로는 엣지가 한 번도 뜰 수 없었다.** 도커가 꺼져 있어 정적 검토로 넘어갔던 그 커밋이다
- **원인** [확인]: 이미지에 들어 있는 도구를 확인하지 않고 "표준 도구니까 있겠지"로 넘어갔다. 알파인 계열은 gettext 가 기본이 아니다
- **대응**: `sed` 로 바꿨다. 기동할 때마다 `apk add` 를 하면 네트워크에 의존하게 된다. 도메인 이름은 `|` 를 포함할 수 없어 구분자로 안전하고, 잘못된 값이 설정 파일에 박히지 않도록 형식 검증도 앞에 붙였다
- **배운 것**: 2026-09-08 기록에 *"정적 검토는 기동 검증을 대신하지 못한다"* 라고 적어 놓고, 그 문장을 쓴 파일이 그대로 하루를 더 갔다. **적어 두는 것과 실행하는 것은 다르다.** 그래서 이번엔 CI 가 `entrypoint.sh` 를 **실제로 실행**하게 만들었다 — 설정 생성 로직을 워크플로에 다시 옮겨 적으면 두 벌이 갈라지고, 갈라진 쪽이 통과하는 CI 는 아무것도 보장하지 않는다


**⑤ 로그가 조용히 사라질 뻔했다**

- **증상**: EC2 절차를 쓰다 발견. `application/logs/` 는 호스트 소유(uid 1000)인데 php-fpm 워커는 `www-data` 로 돈다. 로컬 윈도우 바인드 마운트는 권한이 느슨해 파일이 잘 써졌고(40KB 쌓여 있었다), 리눅스에서만 터졌을 것이다
- **원인** [확인]: `CI_Log` 는 로그 경로에 쓰기 권한이 없으면 **예외를 던지지 않는다.** 생성자에서 `_enabled = FALSE` 로 바꾸고 끝이다. 즉 서버에 올린 뒤 "로그가 안 남네"를 한참 뒤에 알아차리는 종류의 실패다
- **더 나쁜 것**: `config.php` 주석에 *"파일이 아니라 stderr 로 보낸다 — 컨테이너 로그가 곧 애플리케이션 로그다"* 라고 써 놓고 `log_path` 는 기본값(파일)이었다. **주석이 코드보다 앞서 나가 있었다**
- **대응**: `MY_Log` 로 `write_log()` 를 덮어 JSON 한 줄을 stderr 로 보낸다. 임계값 판정은 부모 것을 그대로 써서 `log_threshold` 의미가 갈라지지 않게 했다. 줄마다 `trace_id` 를 붙였고, 그게 엣지 로그·응답 헤더의 값과 같다
- **부수 효과**: 로그 로테이션이 우리 문제가 아니게 됐다. 도커 로그 드라이버가 한다

```
{"ts":"2026-09-08T16:54:30Z","level":"INFO","trace_id":"415096d6...","msg":"Database Driver Class Initialized"}
```

**⑥ `composer install` 이 어디에도 없었다**

- **증상**: EC2 배포 절차를 쓰다 발견. `vendor/` 는 `.gitignore` 이고 Dockerfile 은 composer 바이너리만 복사한다. **클론 직후에는 CodeIgniter 자체가 없다**
- **원인** [확인]: 로컬에서는 처음에 `composer require` 로 설치해 두고 그대로 작업했다. 한 번도 "빈 상태에서 시작"을 해보지 않았다
- **대응**: setup.md 6-1 로 분리하고, 문제해결 표에 "모든 요청이 500" 항목을 넣었다. `--user $(id -u):$(id -g)` 를 빼면 `vendor/` 가 root 소유로 생겨 나중에 손을 못 댄다는 것도 같이
- **배운 것**: 개발 환경은 **누적된 상태**다. 배포 문서를 쓰는 것이 그 누적을 드러내는 가장 싼 방법이다


**⑦ 사용자 데이터에 Compose v2 설치가 빠져 있었다**

```
docker: 'compose' is not a docker command.
```

- **원인** [확인]: Amazon Linux 2023 의 `docker` 패키지에는 Compose v2 가 들어 있지 않고, `dnf` 에 별도 패키지도 없다. `dnf install -y docker git` 한 줄로 끝날 거라고 가정했다
- **대응**: 공식 릴리스 바이너리를 `/usr/libexec/docker/cli-plugins/` 에 넣는다. 버전을 문서에 박지 않고 최신 태그를 리다이렉트에서 받아온다 — 존재하지 않는 태그를 적어 두면 몇 달 뒤 404 로 조용히 실패한다
- **검증**: `amazonlinux:2023` 컨테이너에서 절차를 그대로 돌려 `Docker Compose version v5.5.1` 까지 확인했다. 문서에 적기 전에 돌려 보는 것이 ④에서 배운 것이다


**⑧ 실제 배포에서만 나온 것 다섯**

문서를 아무리 정성껏 써도 서버에 올리기 전에는 알 수 없는 것들이 있었다. 하루치 문서가 실제로는 **동작하지 않는 절차**였다.

| # | 증상 | 원인 |
|---|---|---|
| a | `compose build requires buildx 0.17.0 or later` | AL2023 에 buildx 도 없다. Compose v2 만 넣고 끝난 줄 알았다 |
| b | `MYSQL_ONETIME_PASSWORD: unbound variable` → mysql unhealthy | init 스크립트의 `set -euo pipefail` 이 MySQL 엔트리포인트로 샜다 |
| c | openresty 가 뜨지 못함 (인증서 없음) | `listen 443 ssl` 이 인증서 파일을 요구 → 80번 블록까지 죽음 → 인증서를 영영 못 받는 교착 |
| d | `ERROR 1777: @@GLOBAL.GTID_MODE = OFF` | 복제본 command 에 GTID 를 안 넣었다. 프라이머리에만 넣었다 |
| e | 쿠키 없는 요청 12번이 전부 `rdb1` | 내부 리다이렉트로 access 단계가 요청당 두 번 → 카운터가 2씩 → 한쪽으로 고정 |
| f | production 전환 후 `/diag` 가 500 | CI3 는 Exceptions 클래스를 "에러가 났을 때" 로드한다. production 에서는 억제할 deprecation 이 없어 로드되지 않는데 그걸 참조했다 |

**c 가 가장 값어치 있다.** [setup.md](setup.md) 4장은 *"`docker compose up -d openresty` 하고 certbot 을 돌려라"* 였는데, 새 서버에서는 **첫 줄부터 실패한다.** 문서대로 하면 절대 안 되는 절차를 하루 동안 적어 두고 있었다. 443 블록을 별도 파일로 빼서 인증서가 실재할 때만 생성하도록 고쳤다.

**e 는 두 번 틀렸다.** 처음에 "이미 배정됐으면 건너뛴다" 로 고쳤는데, 서버 블록의 `set $read_target ""` 도 내부 리다이렉트에서 다시 실행되어 플래그가 매번 지워졌다. **재측정하지 않았으면 고쳤다고 믿고 넘어갔을 것이다.** 분포는 여전히 12/12 였다. `$request_id` 해시로 바꾸니 20회에 13/7 로 갈렸다.

**f 는 두 환경의 차이를 잘못 알고 있었다는 증거다.** `development` 와 `production` 의 차이가 "에러가 화면에 보이느냐" 뿐이라고 생각했는데, **로드되는 클래스도 달랐다.** 개발 환경에서만 돌려 봤으면 못 잡았을 종류다.

**d 는 [ADR-007](decisions/ADR-007-read-write-split.md)의 값어치를 증명한다.** "복제본을 흉내내지 않고 실물로 띄운다" 고 정했기 때문에 이 한 줄이 빠진 걸 알 수 있었다. 지연 주입 시뮬레이션이었다면 영영 몰랐다.

### 배포 완료 상태 (2026-09-09)

```
호스트    sshwan.com · lp. · m. · app.   전부 HTTPS (Let's Encrypt, ~2026-12-07)
인프라    t3.small / eu-north-1 / 2 vCPU / 1.9Gi + swap 2Gi
컨테이너  openresty · app · mysql-primary · mysql-replica
스키마    마이그레이션 20260909000700, 테이블 18개 (프라이머리·복제본 일치)
복제      Replica_IO_Running=Yes  Replica_SQL_Running=Yes  Seconds_Behind_Source=0
배정      쿠키 없이 20회 → rdb1 13 / rdb2 7, 쿠키 있으면 고정·재발급 없음
진단      /diag · /healthz · /readyz 전부 200
갱신      cron 매주 월 03:17, --dry-run 통과
```

> **리전이 eu-north-1(스톡홀름)이다.** 서울을 권했는데 실제로는 여기에 만들어졌다. 스톡홀름은 온디맨드 단가가 가장 싼 축이라 비용에는 유리하지만, 한국에서 접속하면 왕복 지연이 250ms 안팎 붙는다. [benchmarks.md](benchmarks.md)에 수치를 적을 때 **어느 리전에서 잰 것인지 반드시 함께 적는다** — 안 그러면 매체 전송 지연과 지리적 지연이 섞인다.

### 결정한 것

| 결정 | 근거 |
|---|---|
| CI3 를 **composer 로** 설치 (`system/` 을 저장소에 복사하지 않음) | 업그레이드가 `composer update` 한 줄이 되고, 저장소 diff 에 프레임워크 코드가 섞이지 않는다 |
| 프론트 컨트롤러는 **`public/index.php` 하나** | 워커도 같은 파일을 쓴다(`php public/index.php cli/dispatch work`). 부트스트랩을 두 벌 두면 언젠가 갈라진다 |
| 마이그레이션 DDL 을 **원문 SQL 로** (dbforge 아님) | `DATETIME(3)`·`BINARY(16)`·인덱스 컬럼 순서·`COLLATE` 를 dbforge 로는 정확히 못 쓴다. 그리고 [data-model.md](data-model.md)와 한 줄씩 대조할 수 있어야 한다 |
| **FK 규칙**: 보존기간이 같으면 걸고, 다르면 걸지 않는다 | `users.signup_visit_id` 에 FK 를 걸면 3개월 파기 배치가 막히거나 CASCADE 로 회원이 지워진다. 보존기간이 설계 제약이 되는 자리 |
| 폴링 인덱스를 **별도 마이그레이션**으로 분리 | 인덱스 있음/없음 두 상태를 명령 한 줄로 오갈 수 있어야 `EXPLAIN` 비교가 재현된다 |
| CI 에 **경계 검사** 추가 (`src/` 가 CI3 를 참조하면 실패) | [ADR-017](decisions/ADR-017-ci3-application-structure.md)의 경계는 문서로만 두면 지켜지지 않는다. 깨지는 순간 단위 테스트가 통째로 불가능해진다 |
| CI 에서 **자동 배포하지 않는다** | 서버 한 대다. 잘못 나갔을 때의 손해가 자동화의 이득보다 크다 |
| 추적 도메인 없이도 기동 | 등록이 늦어진다고 나머지 12일을 놀릴 수 없다. 다만 **무엇이 성립하지 않는지**를 표로 명시해 둔다 — "되는 것처럼" 보이는 게 제일 나쁘다 |
| t3 크레딧을 `standard` 로 | `unlimited` 는 크레딧 소진 후 vCPU-시간당 별도 과금이다. t3 에서 요금이 새는 유일한 구멍이고, CPU 가 오래 100% 라면 그건 돈으로 덮을 게 아니라 고칠 버그다 |

### 다음

- [ ] 추적용 도메인 재선정 — `khan-edge.com` 등록 거절, AWS 쪽 문제로 재시도 보류. `khwanedge.com` 1순위
- [ ] EC2 t3.small 기동 + 예산·이상탐지 알림 설정
- [ ] GA4 속성과 API secret 발급
- [ ] 채널 어댑터(`src/Channel/`)와 `Channels` 브리지
- [ ] `track.js` — `fetch` 경로와 `sendBeacon` 경로 두 벌

---

## 2026-09-08 (화) · D-13

### 한 일

- 도메인 등록 문서와 이름 규칙 정리 (`0714811`, `03e5a2e`)
- ADR-002에 도메인 개수 대안 분석 추가 (`fdbd83c`) — 공개 접미사 목록을 직접 내려받아 확인
- **공고 원문 세 건 확보** — 잡코리아·사람인 모두 상세요강이 이미지라, 사람인 상세 iframe에서 PNG를 내려받아 읽음
- 정정 반영 — ADR-001, 로드맵 의도 층
- ADR-012(관측 가능성 범위), ADR-013(Caddy vs Nginx) 추가
- 작업 기록을 재사용 스킬로 분리해 `~/.claude/skills/worklog/`에 설치

### 막힌 것 / 잘못한 것

**① 공고를 제대로 읽지 않고 13일치 설계를 세웠다** — 이번 최대 실수

- **증상**: "주요 업무에 광고 관련 내용이 없다"는 지적을 받음
- **원인** [확인]: 공고 제목과 **스킬 태그만** 보고 무게중심을 잡았다. 상세요강이 이미지라 텍스트 검색으로는 안 잡혔고, **거기서 멈췄다**
- **대응**: 사람인 상세 iframe(`view-detail?rec_idx=...`)에서 이미지 URL을 뽑아 내려받아 읽음
- **결과**: 다행히 방향은 맞았다. 업무 5줄 중 2줄이 광고·추적이고, 그중 하나는 이 프로젝트를 문장 그대로 서술한다

  > "도메인·Redirect 등 다양한 유입 환경에서의 사용자 및 전환 추적 기능 개발"

- **배운 것**: **운이 좋았을 뿐이다.** 제목·태그와 본문이 어긋났다면 13일을 통째로 날렸다. 채용 공고가 이미지면 **이미지를 열어본다.** 텍스트가 안 잡히는 것을 "정보가 없다"로 넘기지 않는다

**② 전제 두 개가 틀렸다**

| 틀린 전제 | 실제 |
|---|---|
| "공고가 PHP·CodeIgniter를 **요구**한다" | **우대사항**이다. 필수는 HTTP·Cookie·URL Parameter·SQL·Git |
| "PHP 실무 없음이 서류 결격이다" | 경력 요건이 **신입 혹은 3년 이하**. 결격이 아니다 |

로드맵 의도 층의 한 문장("결격을 지운다")이 여기서 무너졌다. **"변별점을 만든다"로 교체.** 하는 일은 거의 같지만 판단 기준이 달라진다 — *"없으면 떨어지나"* 가 아니라 *"다른 지원자도 들고 오나"* 를 묻게 된다.

**③ 공고가 하나인 줄 알았다**

개발실 웹개발자 공고가 **세 개**(광고 매체 연동 / 콘텐츠 플랫폼 / 서비스&인프라 운영)였고 전부 같은 날 마감이다. 복수 지원도 가능하다. 첫날 채용 사이트를 훑을 때 확인했어야 했다.

**④ 도메인 지침을 두 번 정정했다**

- 처음: "두 도메인이 서로 무관해 보여야 한다" → **철회.** same-site 판정은 등록 도메인만 보므로 이름은 실험에 영향이 없다
- 다음: "2개가 필수다" → **부정확.** 공고 태그는 1개로도 전부 커버된다. 두 번째 도메인은 *태그 요건*이 아니라 *변별점*을 산다

  둘 다 **따져보지 않고 단정한 것**이 원인이다. ADR에 "기각한 대안"을 적는 습관이 있었는데도 대안을 다 열거하지 않았다.

### 결정한 것

| 결정 | 근거 |
|---|---|
| 무게중심 **#1 광고 연동 유지**, #3에도 지원 | 프로젝트가 #1 업무 문장과 일치. 방향 전환 비용 0. 주니어 풀에서 희소성이 큼 |
| **절충 = 관측 가능성 승격** | 어트리뷰션 불변 + 구조화 로그·`/health`·`/metrics`·장애 탐지 경로 → [ADR-012](decisions/ADR-012-observability-scope.md) |
| **자체 지표 화면을 버리고 Grafana로 대체** | 컷 순서 1순위였던 항목. 시간 상쇄되고 #1·#3 양쪽에 통함 |
| Prometheus·Grafana는 **`--profile obs`로 필요할 때만** | t3.micro RAM 1GB. 상시 기동은 OOM 위험 |
| Kubernetes **도입 안 함** | #3 우대사항이지만 13일에 불가능. 컨테이너 4개에 K8s는 판단력 문제로 읽힌다 |
| Caddy 유지 (Nginx 아님) | M1이 이틀뿐. certbot 배선에 막히면 뒤가 밀림 → [ADR-013](decisions/ADR-013-caddy-over-nginx.md) |
| 두 지원서의 공통 축 = **"안 보이는 것을 숫자로 만든다"** | 방향 미정 상태에서 "왜 이 포지션인가"에 답하기 위한 서사 |

### 알아낸 것 (공고 원문)

- 전형: 서류 → **조직 적합도 진단(온라인)** → 면접 → 처우 협의 → 최종 합격. 진단은 "면접 시 참고 자료로만 활용되며 합격 여부에 영향 없음"이라 명시
- **1년 계약직이나 "계약 기간 만료 시 근무 평가를 통한 정규직 전환 여부를 결정"** — 전환 경로가 있다
- #3 우대사항에 **Nginx·Apache / Docker·Kubernetes / Prometheus·Grafana·Jenkins·Airflow / PostgreSQL** — 앞서 배포 폴더로 추론한 스택이 공고로 재확인됨

### 결정한 것 (오후 · 아키텍처 착수 전 정리)

| 결정 | 근거 |
|---|---|
| **CI3 + Composer PSR-4 병용** | 컨트롤러·모델은 CI3 관용구, 도메인 로직·어댑터는 `src/` PSR-4. `src/`가 프레임워크 독립이라 **테스트가 성립**한다 → [ADR-017](decisions/ADR-017-ci3-application-structure.md) |
| DI 컨테이너 **미도입** | 어댑터가 3~4개다. 팩토리 하나로 충분 |
| 읽기 커넥션은 **Lua가 넘긴 값**으로 선택 | `AB_READ_TARGET` → `read_rdb1` / `read_rdb2`. 화이트리스트 검증 |
| 세션은 **`database` + 프라이머리 고정** | 세션을 복제본에서 읽으면 로그인이 풀린다. **읽기 분리 도입 시 가장 먼저 터지는 곳** |
| `track.js` 는 **추적 도메인에서 서빙** | 광고주 도메인에서 주면 first-party가 되어 실험이 성립하지 않는다 |
| **`entitlements` 테이블 추가** | 여정에 유료 회차 열람을 넣었는데 스키마에 없었다. 접근통제는 **서빙 시점 판정** |
| 추적 키 언어 축 **구현 안 함** | 관측(`pidko`)은 했으나 11개 언어를 서비스하지 않는다. 문서로만 |
| **Git: 기능 브랜치 → PR → 머지** | 초안의 "Git 약점 보완" 목표. PR 본문에 ADR 링크 |
| **테스트: 단위 + E2E 시나리오** | 재현 절차가 어차피 필요하므로 순증은 약 +0.5일 |
| 추적 도메인 **`.com`으로** | `.click` 은 차단 위험이 측정을 오염시킨다 |

### 다음

- [~] **도메인 등록** — `sshwan.com` 확정(09-09 ICANN 인증 완료). `khan-edge.com` 은 **거절**되어 재선정 필요
- [ ] **ICANN 인증 메일 처리** — 3~15일 내. 놓치면 도메인 정지
- [ ] DNS 전파 확인 후 A 레코드 4개 생성
- [ ] AWS 계정 확인 (프리티어 정책이 2025-07-15부로 변경됨)
- [ ] GA4 속성 + API secret
- [ ] `.env.example`에 관측 프로파일 변수 추가
- [ ] README에 #3용 강조 문단 초안

---

## 2026-09-07 (월) · D-14

### 한 일

- **리서치 완료** — 공개 자료 조사로 도메인 모델 역추론. 관측 문서 6건 + 표준 부록 11건
- **설계 문서 세트 작성** — 아키텍처 · 도메인/쿠키 · API 명세 · 데이터 모델 · ADR 11건 (`dc9a43b`)
- **인프라 구성 작성** — Compose · Caddyfile · Dockerfile · `.env.example` · D1~D2 진단 페이지 (`0ed3ac7`)
- **로드맵 작성** — 의도 / 기획 / 계획 3층 (`65ea26c`)
- **도메인 등록 문서 작성** — [domain-setup.md](domain-setup.md)

### 막힌 것 / 잘못한 것

**① 일정 기준일을 2주 가까이 틀리게 잡고 있었다**

- 증상: 모든 일정 문서가 "마감까지 20일"을 전제로 작성됨
- 원인: 초안 문서의 **파일 작성일(2026-09-01)을 "오늘"로 오인**. 실제 오늘은 2026-09-07
- 영향: 남은 기간이 20일이 아니라 **13일**. 완충 구간이 통째로 사라짐
- 대응: 로드맵 일정 재작성, ADR 11건의 확정일 정정, `research/05`의 일정표는 폐기 표시
- 배운 것: **날짜는 파일이 아니라 시스템에 물어본다.** 일정 문서를 쓰기 전에 `date`를 먼저 친다

**② Caddyfile에 지원되지 않는 지시어를 넣었다**

- 증상 [추정]: `php_fastcgi` 블록 안의 `trusted_proxies`. 기동 시 파싱 에러가 났을 것
- 원인: `reverse_proxy`의 하위 지시어를 `php_fastcgi`도 받는다고 착각
- 대응: 제거. Caddy가 엣지라 애초에 불필요
- **미확인**: Docker 데몬이 꺼져 있어 `caddy validate`로 확인하지 못했다. D1에서 실제 기동으로 검증한다

**③ 검증을 못 한 채로 커밋했다**

- Docker Desktop 미실행 → `docker compose config`, `php -l` 실행 불가
- 대신 변수 참조 관계(compose ↔ Caddyfile ↔ `.env.example`)와 YAML 들여쓰기를 수동 교차 확인
- **정적 검토는 기동 검증을 대신하지 못한다.** D1의 첫 `compose up`이 실질적 첫 검증

### 결정한 것

| 결정 | 근거 |
|---|---|
| MySQL **8.0** 확정 | `FOR UPDATE SKIP LOCKED` 사용. MariaDB였다면 10.6+ 조건이 붙었다 |
| 조사 원본은 **비공개**, 요약만 공개 | 특정 회사의 추적 식별자·인프라 분석을 공개 저장소에 카탈로그로 남기지 않는다 |
| 저장소 문서 전체 **익명화** | `플랫폼 A·B·C`. 사내 CI 잡 이름도 일반화 |
| 도메인 TLD는 **`.com` 양쪽** | 저가 TLD는 차단 위험이 있어 **실험 변수가 된다** → [domain-setup.md](domain-setup.md) 2장 |
| 읽기/쓰기 커넥션 분리 **추가** | 조사 중 복제본 세션 고정 쿠키를 관측 → [ADR-007](decisions/ADR-007-read-write-split.md) |

### 다음

- [ ] 도메인 2개 등록 → **ICANN 인증 메일 즉시 처리**
- [ ] AWS 계정 확인
- [ ] GA4 속성 + API secret
- [ ] GitHub 저장소 생성 후 push

---

<!--
## 다음 항목 양식 — 복사해서 위에 붙인다

## YYYY-MM-DD (요일) · D-N

### 한 일

### 막힌 것
**증상**
**원인** [확인] / [추정]
**대응**
**배운 것**

```
에러 원문을 그대로
```

### 결정한 것
| 결정 | 근거 |

### 다음
- [ ]
-->
