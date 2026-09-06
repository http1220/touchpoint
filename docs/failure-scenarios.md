# 실패 시나리오

> **상태: 스켈레톤.** 시나리오와 **검증 방법**만 확정했고, `결과`·`대응` 열은 실제로 재현한 뒤 채운다.
> 이 문서와 [benchmarks.md](benchmarks.md)가 이 저장소의 진짜 차별점이다. 코드는 누구나 쓴다.

기록 규칙:

- **재현 절차를 먼저 적고**, 그다음 결과를 적는다. 결과부터 적으면 재현 불가능한 문서가 된다
- 예상과 다르면 **예상을 고치지 말고 둘 다 적는다.** 틀린 예상이 배운 지점이다
- 스크린샷·`curl` 출력·`EXPLAIN`은 원문 그대로 붙인다

---

## A. 쿠키 · 크로스사이트

### A-1. 서드파티 쿠키 차단 — 브라우저별

| 브라우저 | 설정 | `ab_vid`(1st) | `ab_tid`(3rd) | 예상 | 결과 |
|---|---|---|---|---|---|
| Chrome | 기본 | | | 통과 (2025 폐지 철회) | ☐ |
| Chrome | 서드파티 차단 ON | | | `ab_tid` 차단 | ☐ |
| Chrome | + `Partitioned` | | | 파티션 단위 통과 | ☐ |
| Safari | 기본 (ITP) | | | **차단** | ☐ |
| Firefox | 기본 (TCP) | | | **차단** | ☐ |

**재현 절차**
1. `https://lp.toonlab.example/go?work=1&pid=test` 접속
2. DevTools → Application → Cookies에서 두 도메인의 쿠키 확인
3. Network 탭에서 `POST api.abridge.example/collect` 요청 헤더에 `Cookie: ab_tid`가 붙는지 확인
4. 브라우저 설정을 바꿔 2~3 반복

**확인할 것**: 어트리뷰션이 **어느 지점에서 끊기는가.** 쿠키가 없어도 `visit_uid`가 URL로 전달되면 살아남는지.

---

### A-2. `SameSite` 설정 누락

| 설정 | 예상 | 결과 |
|---|---|---|
| `ab_tid`에 `SameSite=Lax` | cross-site 요청에 **미전송** | ☐ |
| `SameSite=None` + `Secure` 누락 | 브라우저가 **쿠키 자체를 거부** | ☐ |
| `Partitioned` + `SameSite=Lax` | `Partitioned` **무효** | ☐ |

**재현**: `.env`의 쿠키 속성을 바꿔 재배포 → DevTools 콘솔 경고 메시지 원문 기록.

---

### A-3. same-site 대조군

| 호출 | 관계 | 쿠키 전송 | 결과 |
|---|---|---|---|
| `lp.toonlab` → `api.abridge` | cross-site | `SameSite=None` 필요 | ☐ |
| `lp.toonlab` → `app.toonlab` | **same-site** | `Lax`로도 전송 | ☐ |

> **이 표가 [ADR-002](decisions/ADR-002-two-registered-domains.md)의 증명이다.** 같은 CORS 상황인데 쿠키만 다르게 동작하는 것을 나란히 보여준다.

---

## B. CORS

### B-1. preflight 발생 조건

| 요청 | preflight | 결과 |
|---|---|---|
| `fetch` + `Content-Type: application/json` | **발생** | ☐ |
| `fetch` + `text/plain` | 없음 | ☐ |
| `navigator.sendBeacon` | **없음** (`text/plain` 고정) | ☐ |
| `fetch` + 커스텀 헤더 `X-Trace-Id` | 발생 | ☐ |

---

### B-2. `Allow-Origin: *` 로 바꾸면

| 설정 | 예상 | 결과 |
|---|---|---|
| `Allow-Origin: *` + `credentials: 'include'` | **브라우저가 응답을 거부.** 쿠키 전송 불가 | ☐ |
| 정확한 오리진 + `Allow-Credentials: true` | 정상 | ☐ |
| 오리진 반향하면서 **`Vary: Origin` 누락** | CDN·프록시가 **다른 오리진 응답을 캐시** | ☐ |

**기록할 것**: 브라우저 콘솔 에러 메시지 원문. `The value of the 'Access-Control-Allow-Origin' header ... must not be the wildcard '*' when the request's credentials mode is 'include'`

---

### B-3. `sendBeacon` 트레이드오프

| 항목 | `fetch` | `sendBeacon` | 결과 |
|---|---|---|---|
| preflight | 발생 | 없음 | ☐ |
| 커스텀 헤더 | 가능 | **불가** | ☐ |
| 페이지 이탈 중 전송 | 취소될 수 있음 | **보장** | ☐ |
| 페이로드 크기 제한 | 큼 | 브라우저별 제한(약 64KB) | ☐ |

**재현**: 랜딩에서 즉시 다른 페이지로 이동하며 두 방식의 도달률 비교. 100회 반복해 수치로.

> **실제로 부딪힌 사람만 아는 내용이라 면접에서 바로 티가 난다.**

---

## C. 리다이렉트

### C-1. 301 vs 302

| 코드 | 목적지 변경 반영 | 클릭 집계 | 결과 |
|---|---|---|---|
| 302 + `Cache-Control: no-store` | 즉시 | 매번 | ☐ |
| 302 (캐시 헤더 없음) | ? | ? | ☐ |
| **301** | **반영 안 됨** | **서버에 안 옴** | ☐ |

**재현**
1. 301로 배포 → 브라우저에서 링크 클릭 → 목적지 도달
2. 서버에서 목적지를 바꿔 재배포
3. **같은 브라우저에서** 링크 재클릭 → 예전 목적지로 가는지 확인
4. 서버 로그에 요청이 남지 않는 것 확인

---

### C-2. 리다이렉트 중 파라미터 유실

| 방식 | URL 공유 시 | 파라미터 길이 | 변조 가능 | 결과 |
|---|---|---|---|---|
| A. 전량 전달 (`?utm_*&gclid`) | **남의 유입이 내 클릭으로 집계** | 제한 있음 | 가능 | ☐ |
| B. `visit_uid`만 (`?vid=`) | 안전 | 짧음 | 불가 | ☐ |

**재현**: A 방식 URL을 복사해 다른 브라우저(새 방문자)로 접속 → `touchpoints`에 원래 캠페인이 그대로 붙는지 확인.

> **B가 왜 더 안전한지**를 이 실험으로 증명한다.

---

### C-3. 오픈 리다이렉트

| 입력 | 예상 | 결과 |
|---|---|---|
| `/go?work=1&return=/l/1` | 정상 | ☐ |
| `/go?work=1&return=https://evil.example` | **허용 목록에서 거부** | ☐ |
| `/go?work=1&return=//evil.example` | 거부 (스킴 상대 URL) | ☐ |

> 플랫폼 A 소셜 로그인이 `?return=<경로>`를 쓴다. 오픈 리다이렉트의 고전적 위치다.

---

## D. 매체 전송 · 워커

### D-1. 워커 중복 실행

| 조건 | 예상 | 결과 |
|---|---|---|
| 워커 1개 | 중복 0 | ☐ |
| 워커 4개 + `SKIP LOCKED` | **중복 0** | ☐ |
| 워커 4개 + `SKIP LOCKED` 제거 | 락 대기로 처리량 급감 | ☐ |

**재현**
```bash
docker compose up -d --scale worker=4
# 전환 1,000건 주입 후
SELECT outbox_id, COUNT(*) FROM dispatch_log
 WHERE http_status = 200 GROUP BY outbox_id HAVING COUNT(*) > 1;
-- 결과가 0행이어야 한다
```

---

### D-2. 매체 API 타임아웃

| 시나리오 | 예상 | 결과 |
|---|---|---|
| 첫 시도 타임아웃 | `failed` → `next_retry_at` 설정 | ☐ |
| 재시도 5회 모두 실패 | `dead` 처리, 알림 | ☐ |
| 3회째 성공 | `sent`, 최종 성공률에 반영 | ☐ |
| 매체가 5xx 반환 | 재시도 | ☐ |
| 매체가 4xx 반환 | **재시도 안 함** (요청 자체가 잘못됨) | ☐ |

> **4xx와 5xx를 구분하지 않으면 잘못된 페이로드를 5번 더 보낸다.**

---

### D-3. 중복 전환

| 시나리오 | 예상 | 결과 |
|---|---|---|
| 같은 `dedup_key` 2회 | 2번째는 `200` + 기존 UID | ☐ |
| 결제 웹훅 중복 수신 | 상태 1회만 전이 | ☐ |
| 웹훅 순서 역전 (`captured` 뒤 `pending`) | 과거 상태 **무시** | ☐ |

---

## E. 데이터 정합성

### E-1. 복제 지연 (read-after-write)

| `REPLICA_LAG_MS` | 전환 직후 `/metrics` | 결과 |
|---|---|---|
| `0` | 즉시 보임 | ☐ |
| `2000` | **2초간 안 보임** | ☐ |
| `2000` + 쓰기 커넥션으로 조회 | 즉시 보임 | ☐ |

> 플랫폼 A가 `…slave=sdb3` 쿠키로 세션을 복제본에 고정하는 이유를 재현한다 → [ADR-007](decisions/ADR-007-read-write-split.md)

---

### E-2. 보존기간 파기

| 시나리오 | 예상 | 결과 |
|---|---|---|
| `visits`를 3개월 기준으로 파기 | 삭제됨 | ☐ |
| 파기 후 해당 회원의 **가입 경로** 조회 | **`users` 스냅샷으로 살아있음** | ☐ |
| 파기 후 `touchpoints` 조인 | NULL | ☐ |

> **스냅샷 비정규화가 정당한 이유**를 이 실험으로 증명한다. 원본이 법적으로 사라져도 회원 유입 경로는 5년 남아야 한다.

---

## 요약표 (완성 시 README로 발췌)

| # | 시나리오 | 무엇이 깨지는가 | 어떻게 막았는가 |
|---|---|---|---|
| A-1 | 서드파티 쿠키 차단 | | |
| A-2 | `SameSite` 누락 | | |
| B-2 | `Allow-Origin: *` | | |
| B-3 | `sendBeacon` 헤더 불가 | | |
| C-1 | 301 캐시 | | |
| C-2 | 리다이렉트 파라미터 유실 | | |
| D-1 | 워커 중복 실행 | | |
| D-2 | 매체 타임아웃 | | |
| E-1 | 복제 지연 | | |
| E-2 | 보존기간 파기 | | |
