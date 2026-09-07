# ADR-001 · PHP 8.3 + CodeIgniter 4

**상태**: 확정 (2026-09-07)

## 배경

지원자의 실무 강점은 C#·Node.js·React이며 **PHP 실무 경험이 없다.**

**정정 (2026-09-08)**: 이 문서 초안은 "공고가 PHP·CodeIgniter를 **요구**한다"고 적었다. 공고 원문을 확인한 결과 **부정확했다.**

| 구분 | 실제 공고 문구 |
|---|---|
| **필수** | 기본적인 웹 개발 역량 · **HTTP 요청·응답, Cookie, URL Parameter** 이해 · MySQL 등 관계형 DB와 SQL 기본 · Git |
| **우대** | GA·GTM · Meta Pixel·Google Ads 전환 추적 · 외부 API·SDK 연동 · Domain·Redirect·CORS 이슈 · **PHP 또는 CodeIgniter 기반 개발 경험** |

**PHP·CodeIgniter는 우대사항이다.** 그리고 경력 요건이 **"신입 혹은 3년 이하"** 이므로, PHP 실무가 없는 것은 애초에 결격이 아니다.

## 결정

**PHP 8.3 + CodeIgniter 4.5**를 쓴다. 정정 후에도 결론은 바뀌지 않는다.

## 근거

근거의 성격이 바뀐다. **"결격을 지운다"가 아니라 "우대사항을 채운다"** 이다.

필수 요건(HTTP·Cookie·URL Parameter·SQL·Git)은 언어와 무관하게 충족할 수 있다. 그러나 **우대사항 다섯 줄 중 넷을 이 프로젝트가 직접 겨냥**하고 있고(GA·Meta·외부 SDK·Domain/Redirect/CORS), 마지막 한 줄이 PHP·CodeIgniter다. **넷을 채우면서 다섯째만 다른 언어로 가는 것은 이유가 없다.**

그리고 실제 운영 스택이 CodeIgniter라는 것은 추측이 아니라 **관측으로 확인됐다**:

| 근거 | 값 |
|---|---|
| 세션 쿠키 | `…ci_session` — CI 기본 세션 쿠키명에 서비스 접두사가 붙은 형태 |
| URL 라우팅 | `/webtoon/top100/type/famous/age/20` — CI의 `/{controller}/{method}/{key}/{value}` |
| 다른 채용공고 | "CodeIgniter 기반 PHP MVC 개발 유경험자" 명시 |

→ [조사 요약](../research-method.md)

## 기각한 대안

| 대안 | 기각 사유 |
|---|---|
| **Laravel** | 공고 우대사항이 CodeIgniter를 지목했고 실제 운영 스택도 CI다. 맞추는 게 우선 |
| **익숙한 Node.js로 만들고 PHP는 포기** | 필수 요건은 충족하지만 **우대사항 다섯 줄 중 하나를 스스로 버리는 것**이 된다. 경쟁자가 신입~3년 풀이라 우대사항이 곧 변별점이다 |
| Node.js / NestJS | 강점이지만 공고와 무관. "PHP 지원자"가 아니게 된다 |
| Python / FastAPI | 사내에 Python 백엔드가 있는 건 확인됐으나([조사 요약](../research-method.md)), 공고 요구는 PHP다. 13일에 둘 다 하면 둘 다 얕아진다 |

## 결과

- **감수**: 13일 중 2~3일을 CI4 학습에 쓴다. 숙련도가 낮아 코드가 관용적이지 않을 수 있다
- **완화**: 면접에서 숨기지 않는다 — "PHP는 이 프로젝트가 전부입니다. 다만 C#·Node로 같은 계층을 다뤄왔습니다"
- CI4의 Migrations를 쓰므로 **스키마 변경 이력이 커밋에 남는다**
