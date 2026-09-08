# ADR-001 · PHP + CodeIgniter 3

**상태**: 확정 (2026-09-08) — **CI4에서 CI3로 변경.** [ADR-014](ADR-014-stack-alignment.md) 정렬 원칙 적용

## 배경

지원자의 실무 강점은 C#·Node.js·React이며 PHP 실무 경험이 없다.

### 두 번의 정정

**1차 (09-08 오전)**: 초안은 "공고가 PHP·CodeIgniter를 **요구**한다"고 적었다. 공고 원문을 보니 **우대사항**이었고, 경력 요건은 **"신입 혹은 3년 이하"** 였다. 즉 PHP 실무 부재는 결격이 아니다.

**2차 (09-08 오후)**: 세션 쿠키를 디코드해 **대상 조직이 CodeIgniter 2.x를 쓴다**는 것을 확인했다. 그런데 이 문서는 CI **4**를 택하고 있었다.

```
ZZAMTOONcisession=
a:4:{s:10:"session_id";   s:32:"377f8a3eb...";
     s:10:"ip_address";   s:12:"59.8.244.183";
     s:10:"user_agent";   s:107:"Mozilla/5.0 ...";
     s:13:"last_activity";i:1788824210;}40d18109b07b6cd1...
```

**직렬화된 userdata 전체 + SHA1 HMAC.** CI 2.x의 쿠키 세션 드라이버 형식이다. CI3부터는 세션 ID만 담는다.

## 결정

**PHP 8.2 + CodeIgniter 3.1.x** 를 쓴다.

## 근거

### 왜 CI2가 아닌가

| 사유 | |
|---|---|
| PHP 8 미지원 | CI 2.x는 PHP 8에서 동작하지 않는다 |
| 보안 패치 부재 | 2.2.6이 마지막, 약 11년째 |
| **공개 서버 노출** | 인터넷에 붙는 서버에 EOL 프레임워크를 올리는 것은 무책임하다 |

**정렬이 안전을 이길 수는 없다.** 이건 [ADR-014](ADR-014-stack-alignment.md)의 "타협" 범주다.

### 왜 CI4가 아닌가 — 여기가 핵심

CI4는 **완전 재작성**이다. 네임스페이스·PSR-4·엔티티·서비스 컨테이너. **CI2 코드베이스를 유지보수하는 데 배우는 것이 거의 없다.**

| | CI 2.x (대상) | **CI 3** | CI 4 |
|---|---|---|---|
| 모델 로드 | `$this->load->model()` | **동일** | `new \App\Models\X()` |
| URI 라우팅 | 세그먼트 `/{c}/{m}/{k}/{v}` | **동일** | 명시적 라우트 정의 |
| 헬퍼·라이브러리 | `$this->load->helper()` | **동일** | `helper()` 함수, 서비스 |
| 쿼리 빌더 | `$this->db->…` | **거의 동일** | `$db->table()` |
| 세션 | **쿠키에 데이터 전체** | 세션 ID만 | 세션 ID만 |

**CI3는 CI2의 관용구를 그대로 쓴다.** 그리고 공식 마이그레이션 경로가 **CI2 → CI3 → CI4**다.

> **CI3로 만들면 "그들의 다음 단계"에 서 있는 것이고, CI4로 만들면 두 칸 건너뛴 곳에 있다.**

### 그리고 세션 드라이버가 좋은 면접 소재가 된다

CI2 → CI3의 대표적 파괴적 변경이 **정확히 세션 드라이버**다.

| | CI2 | CI3 |
|---|---|---|
| 저장 위치 | **클라이언트 쿠키** | 서버 (files·DB·Redis) |
| 쿠키 내용 | 직렬화 userdata + HMAC | 세션 ID만 |
| 크기 한계 | **4KB를 넘으면 조용히 잘림** | 없음 |
| 무결성 | HMAC 하나에 의존 | 서버 보관 |

관측된 쿠키가 CI2 형식이라는 건 **아직 이 마이그레이션을 하지 않았다**는 뜻이고, 그 마이그레이션은 *"세션이 커지면 로그인이 간헐적으로 풀리는"* 문제를 없앤다.

> **이 프로젝트에서 CI3 세션을 쓰는 것 자체가 그 차이를 몸으로 아는 일이 된다.**

## 기각한 대안

| 대안 | 기각 사유 |
|---|---|
| **CodeIgniter 2** | PHP 8 불가, 보안 패치 부재. 공개 서버 불가 |
| **CodeIgniter 4** | 관용구가 달라 CI2 유지보수 경험으로 이어지지 않는다. **정렬 원칙 위반** |
| Laravel | 대상 스택이 아니다 |
| Node.js / NestJS | 강점이지만 이 프로젝트의 목적과 무관 |
| Python / FastAPI | 사내에 Python 계통이 있으나 공고 요구는 PHP다 |

## 결과

- **감수**: CI3는 유지보수 모드다. 제한적 보안 패치만 나오고 **공식 지원 PHP는 7.4**다
- **PHP 8.2로 간다**. PHP 7.4는 이미 EOL이고, 8.1도 2025-12에 끝났다. CI3를 8.2에서 돌리면 deprecated 경고와 소소한 비호환이 나오는데, **그걸 고치는 과정 자체가 이 프로젝트가 만들려는 경험**이다. 부딪힌 것은 [worklog](../worklog.md)에 기록한다
- **README에 명시**: "CI3는 유지보수 모드입니다. 신규 서비스라면 CI4가 맞지만, 이 프로젝트는 대상 코드베이스(CI2)와의 근접성을 우선했습니다"
- **감수**: 13일 중 2~3일을 CI3 학습에 쓴다. CI4보다는 단순해 부담이 적다
- **완화**: 면접에서 숨기지 않는다 — "PHP는 이 프로젝트가 전부입니다. 다만 C#·Node로 같은 계층을 다뤄왔습니다"

---

## 부록 · PHP 8.2 deprecation 을 어떻게 다뤘나 (2026-09-09 추가)

"deprecated 경고가 나오는데 그걸 고치는 게 경험"이라고 위에 적었다. 실제로 부딪혀 보니 **경고가 두 종류**였고, 둘을 같이 다루면 안 됐다.

```
PHP Deprecated:  Creation of dynamic property CI_URI::$config is deprecated
                 in .../vendor/codeigniter/framework/system/core/URI.php on line 102
PHP Deprecated:  Creation of dynamic property Migrate::$migration is deprecated
                 in .../vendor/codeigniter/framework/system/core/Loader.php on line 1284
```

두 줄 다 `Loader`/`URI` 에서 났지만, **프로퍼티가 붙는 대상**이 다르다.

| | 대상 | 우리가 고칠 수 있나 |
|---|---|---|
| `CI_URI::$config` | 프레임워크 자기 자신 | **아니다.** `vendor/` 안이다 |
| `Migrate::$migration` | 우리 컨트롤러 | 그렇다 |

### 하지 않은 것

`error_reporting` 에서 `E_DEPRECATED` 를 빼는 것. 한 줄이면 조용해지지만, **우리가 새로 쓴 코드의 deprecation 까지 같이 사라진다.** 8.2 위에서 CI3 를 돌리기로 한 이유가 "부딪히면서 고치는 경험"인데, 그 경험이 오는 통로를 스스로 막는 셈이다.

### 한 것

**우리 코드** — `MY_Controller` 에 `#[\AllowDynamicProperties]`. 속성은 상속되므로 모든 컨트롤러에 한 번에 적용된다. 클래스마다 `public $db; public $session;` 을 선언하는 방법도 있지만, 무엇을 로드하는지는 요청 경로마다 다르고 선언을 빠뜨리면 경고가 되살아난다.

**프레임워크 내부** — `MY_Exceptions` 로 CI3 의 에러 처리 진입점 두 개를 감싸고, **발생 위치가 `vendor/codeigniter/framework/` 인 `E_DEPRECATED` 만** 걸러낸다.

```php
class MY_Exceptions extends CI_Exceptions
{
    const FRAMEWORK_PATH = 'vendor/codeigniter/framework/';
    // log_exception() / show_php_error() 에서 이 경로의 E_DEPRECATED 만 조기 반환
}
```

억제한 건수는 세어서 `/diag` 에 띄운다. **안 보이게 하는 것과 없는 것처럼 구는 것은 다르다.** 숫자가 늘어나면 프레임워크를 더 깊이 건드리고 있다는 신호다.

### 면접에서 말할 한 줄

> "지원 범위 밖의 런타임에서 레거시 프레임워크를 돌릴 때, 경고를 끄는 범위를 **심각도가 아니라 발생 위치로** 좁혔습니다. 그래야 내가 새로 쓴 코드의 경고는 계속 보입니다."
