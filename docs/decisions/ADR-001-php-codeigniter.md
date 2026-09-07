# ADR-001 · PHP 8.3 + CodeIgniter 4

**상태**: 확정 (2026-09-07)

## 배경

지원 대상 공고가 PHP·CodeIgniter를 요구한다. 지원자의 실무 강점은 C#·Node.js·React이며 **PHP 실무 경험이 없다.**

## 결정

**PHP 8.3 + CodeIgniter 4.5**를 쓴다. 익숙한 스택으로 대체하지 않는다.

## 근거

공고 태그를 그대로 따르는 것이 목적이다. 이 포트폴리오는 "좋은 설계"를 자랑하는 물건이 아니라 **"PHP를 못 한다"는 결격을 지우는 물건**이다. 다른 언어로 잘 만들면 그 결격이 그대로 남는다.

CodeIgniter는 추측이 아니라 **관측으로 확인됐다**:

| 근거 | 값 |
|---|---|
| 세션 쿠키 | `…ci_session` — CI 기본 세션 쿠키명에 서비스 접두사가 붙은 형태 |
| URL 라우팅 | `/webtoon/top100/type/famous/age/20` — CI의 `/{controller}/{method}/{key}/{value}` |
| 다른 채용공고 | "CodeIgniter 기반 PHP MVC 개발 유경험자" 명시 |

→ [조사 요약](../research-method.md)

## 기각한 대안

| 대안 | 기각 사유 |
|---|---|
| **Laravel** | 공고가 CodeIgniter를 지목했다. 실무 스택에 맞추는 게 우선 |
| Node.js / NestJS | 강점이지만 공고와 무관. "PHP 지원자"가 아니게 된다 |
| Python / FastAPI | 사내에 Python 백엔드가 있는 건 확인됐으나([조사 요약](../research-method.md)), 공고 요구는 PHP다. 13일에 둘 다 하면 둘 다 얕아진다 |

## 결과

- **감수**: 13일 중 2~3일을 CI4 학습에 쓴다. 숙련도가 낮아 코드가 관용적이지 않을 수 있다
- **완화**: 면접에서 숨기지 않는다 — "PHP는 이 프로젝트가 전부입니다. 다만 C#·Node로 같은 계층을 다뤄왔습니다"
- CI4의 Migrations를 쓰므로 **스키마 변경 이력이 커밋에 남는다**
