# ADR-017 · CodeIgniter 3 위에서의 애플리케이션 구조

**상태**: 확정 (2026-09-08)

## 배경

[ADR-001](ADR-001-php-codeigniter.md)에서 CI3를 택했다. 그런데 CI3에는 **네임스페이스도 DI 컨테이너도 없다.** 모든 것이 `$this->load->library()` / `$this->load->model()` 로 들어온다.

여기에 이 프로젝트의 핵심 구조 세 개를 얹어야 한다.

| 구조 | CI3에서의 문제 |
|---|---|
| **채널 어댑터** (GA4·Meta·알림) | 인터페이스와 다형성이 필요한데 `load->library()`는 문자열 기반이다 |
| **읽기/쓰기 커넥션 분리** | Lua가 넘긴 값에 따라 **런타임에** 커넥션을 골라야 한다 |
| **세션** | 복제본이 있으니 세션 읽기가 복제본으로 가면 안 된다 |

## 결정

### ① CI3 관용구와 Composer PSR-4를 **병용**한다

`application/config/config.php`:

```php
$config['composer_autoload'] = FCPATH . '../vendor/autoload.php';
```

그러면 경계가 이렇게 갈린다.

| 영역 | 방식 | 이유 |
|---|---|---|
| 컨트롤러 · 모델 · 뷰 | **CI3 관용구** (`$this->load->model()`) | 대상 코드베이스와 같은 관용구. 정렬 원칙 |
| 도메인 로직 · 어댑터 · 값 객체 | **PSR-4 네임스페이스** (`App\Channel\Ga4Channel`) | 인터페이스·다형성·테스트가 필요한 곳 |

```
application/          ← CI3 영역
├── controllers/      Landing.php · Collect.php · Conversion.php · Purchase.php
├── models/           Visit_model.php · Conversion_model.php · Coin_model.php
├── libraries/        Ci3 브리지 (composer 객체를 CI3에 연결하는 얇은 층)
└── config/

src/                  ← PSR-4 영역 (App\)
├── Channel/          ChannelInterface · Ga4Channel · MetaChannel · NoopChannel
├── Notify/           NotifierInterface · WebPushNotifier · SmsNotifier
├── Payment/          GatewayInterface · StubGateway · PgAGateway · PgBGateway
├── Attribution/      TouchpointResolver · DedupKey
├── Coin/             LedgerService (만료·소진 순서)
└── Support/          Clock · TraceId

tests/                PHPUnit — src/ 를 테스트한다
```

### 왜 이 경계인가

**`src/` 는 CI3에 의존하지 않는다.** `$this->load` 도 `$this->db` 도 쓰지 않는다. 필요한 것은 생성자로 주입받는다.

그래서 **테스트가 가능해진다.** CI3는 테스트 지원이 약한데, 어댑터·원장·멱등성 로직이 프레임워크 밖에 있으면 순수 PHPUnit으로 검증된다. 컨트롤러는 얇게 두고 조립만 시킨다.

> **이건 레거시 CI를 점진 개선하는 표준적인 방법이기도 하다.**
> 프레임워크를 갈아엎지 않고 새 로직만 밖으로 빼는 것 — 대상 조직의 **"기술 부채를 발견하고 지속적인 개선"** 과 같은 접근이다.

### ② 어댑터 조립 — 컨테이너 없이

DI 컨테이너를 도입하지 않는다. **팩토리 하나**로 충분하다.

```php
// application/libraries/Channels.php  — CI3 브리지
class Channels {
    public function all(): array {          // .env의 CHANNELS 목록을 읽어
        // App\Channel\Ga4Channel, MetaChannel ... 을 조립해 배열로 반환
    }
}
```

워커는 `$this->channels->all()` 로 받아 인터페이스만 알고 돌린다. **신규 매체 = `src/Channel/` 에 클래스 1개 + `.env` 한 줄** ([ADR-005](ADR-005-channel-adapter.md))이 CI3에서도 그대로 성립한다.

### ③ 읽기 커넥션 — Lua가 고른 것을 따른다

CI3는 다중 DB 그룹을 지원한다. 세 그룹을 정의한다.

```php
$db['write']     = [ 'hostname' => getenv('DB_WRITE_HOST'),  ... ];  // 프라이머리
$db['read_rdb1'] = [ 'hostname' => getenv('DB_READ_RDB1'),   ... ];  // 지연 0
$db['read_rdb2'] = [ 'hostname' => getenv('DB_READ_RDB2'),   ... ];  // 실제 복제본
```

```php
// 읽기 커넥션 선택 — Lua가 FastCGI 파라미터로 넘긴 값
$target = $_SERVER['AB_READ_TARGET'] ?? 'rdb1';   // rdb1 | rdb2
$read = $this->load->database('read_' . $target, TRUE);
```

| 경로 | 커넥션 |
|---|---|
| 수집 · 전환 · 가입 · 결제 · 워커 | **`write`** — 쓰기 및 쓰기 직후 읽기 |
| 조회 (내 코인, 열람 이력, 지표) | **배정된 `read_*`** — 여기서 지연이 드러난다 |

> **화이트리스트로 검증한다.** `AB_READ_TARGET` 은 Lua가 넣지만 값을 그대로 문자열 결합하지 않는다.

### ④ 세션 — `database` 드라이버 + **프라이머리 고정**

| 드라이버 | 기각/채택 |
|---|---|
| `files` | app 컨테이너가 하나면 되지만 스케일하면 깨진다 |
| **`database`** | **채택.** 단 세션 커넥션은 **`write` 그룹 고정** |
| `redis` | 컨테이너 추가. 이 규모에 불필요 |

**세션을 복제본에서 읽으면 안 된다.** 로그인 직후 세션이 복제되기 전에 읽으면 로그인이 풀린다. 복제 지연이 인증에까지 번지는 경우이고, **읽기 분리를 도입할 때 가장 먼저 터지는 곳**이다.

> **그리고 이게 CI2와의 대비를 만든다.**
>
> | | CI2 (대상) | CI3 (이 프로젝트) |
> |---|---|---|
> | 저장 | **클라이언트 쿠키**에 직렬화 | **서버 DB** |
> | 쿠키 | userdata 전체 + HMAC | 세션 ID만 |
> | 한계 | **4KB 넘으면 조용히 잘림** | 없음 |
> | 새 문제 | — | **세션 읽기가 복제본으로 가면 안 됨** |
>
> 마이그레이션은 문제를 없애는 동시에 **새 문제를 만든다.** 이걸 직접 겪는 게 이 결정의 값어치다.

### ⑤ `track.js` 는 추적 도메인에서 서빙한다

```
lp.<광고주도메인>  의 페이지가
<script src="https://api.<추적도메인>/track.js"> 를 로드
```

**이게 현실의 서드파티 스크립트 구조다.** 광고주 도메인에서 서빙하면 first-party가 되어 CSP·CORS 실험이 성립하지 않는다.

- 의존성 0 (프레임워크 없음). 광고 태그가 바닐라인 이유와 같다 → [ADR-009](ADR-009-no-spa.md)
- `fetch` 경로와 `sendBeacon` 경로 두 가지를 모두 구현해 비교한다

## 기각한 대안

| 대안 | 기각 사유 |
|---|---|
| 전부 CI3 관용구로 (`libraries/`) | 어댑터에 인터페이스를 못 준다. **테스트도 사실상 불가** |
| 전부 PSR-4로, CI3는 라우팅만 | 정렬 원칙 위반. 대상 코드베이스는 CI3 관용구를 쓴다 |
| DI 컨테이너 도입 | 어댑터가 3~4개다. 팩토리 하나로 충분하다 |
| 커넥션을 요청 시작 시 하나로 고정 | 쓰기 직후 읽기를 프라이머리로 돌릴 수 없다 |
| 세션 `files` | 스케일 시 깨지고, **복제 지연과 세션의 관계를 배우지 못한다** |
| `track.js` 를 광고주 도메인에서 서빙 | first-party가 되어 실험이 성립하지 않는다 |

## 결과

- **감수**: CI3 안에 두 가지 스타일이 공존한다. **경계를 문서로 명확히 해야** 혼란이 안 생긴다 — 이 문서가 그 역할
- **얻음**: `src/` 가 프레임워크 독립이라 **PHPUnit 단위 테스트가 성립**한다
- **얻음**: "레거시 프레임워크 위에 새 로직을 어떻게 얹는가"에 대한 실제 답을 갖게 된다
- **검증**: `src/` 안에서 `CI_Controller`·`$this->load`·`get_instance()` 를 참조하지 않는다. `grep` 으로 확인 가능하다
