# 실패 시나리오

> **상태: 스켈레톤.** 시나리오와 **검증 방법**만 확정했고, `결과`·`대응` 열은 실제로 재현한 뒤 채운다.
> 이 문서와 [benchmarks.md](benchmarks.md)가 이 저장소의 진짜 차별점이다. 코드는 누구나 쓴다.

기록 규칙:

- **재현 절차를 먼저 적고**, 그다음 결과를 적는다. 결과부터 적으면 재현 불가능한 문서가 된다
- 예상과 다르면 **예상을 고치지 말고 둘 다 적는다.** 틀린 예상이 배운 지점이다
- 스크린샷·`curl` 출력·`EXPLAIN`은 원문 그대로 붙인다

---

## A. 쿠키 — 범위에서 뺀 구간

**A-1·A-2는 하지 않는다.** 등록 도메인을 1개로 정했기 때문이다 → [ADR-018](decisions/ADR-018-single-registered-domain.md)
설계와 이유는 남겨 둔다. 못 한 것과 모르는 것은 다르다.

### A-1. 서드파티 쿠키 차단 — 브라우저별 ✗ 범위 밖

원래 계획은 Chrome(기본/차단ON/`Partitioned`) · Safari(ITP) · Firefox(TCP)에서 first-party 쿠키와 third-party 쿠키의 전송 여부를 나란히 재는 것이었다.

**성립하지 않는 이유**: 브라우저는 same-site를 **eTLD+1**로 판정한다. `lp.sshwan.com` → `api.sshwan.com`은 같은 사이트이므로 서드파티 쿠키 차단이 **발동할 조건 자체가 없다.** 서브도메인으로는 흉내도 되지 않는다.

> 2025년 현황만 적어 둔다 — Chrome은 서드파티 쿠키 폐지를 **철회**했고(2024-07, 2025-04), Privacy Sandbox는 2025-10-17에 종료됐다. Safari·Firefox는 계속 차단한다. **그래서 이 문제는 "사라지는 문제"가 아니라 "브라우저 편차 문제"다.** 이 인식은 조사에서 얻은 것이고 도메인과 무관하게 유효하다.

### A-2. `SameSite` 설정 누락 ✗ 범위 밖

`SameSite=None`이 필요한 상황은 cross-site 요청에서만 생긴다. same-site에서는 `Lax`로 충분하므로 **실패가 일어나지 않는다.**

`Partitioned`(CHIPS) 역시 `SameSite=None; Secure`와 함께여야 효력이 있으므로 같이 빠진다.

### A-3. same-site · cross-origin 대조 ✅ 이건 한다

| 호출 | 사이트 | 오리진 | 쿠키 | CORS | 결과 |
|---|---|---|---|---|---|
| `lp.sshwan.com` → `app.sshwan.com` | 같음 | 같음(호스트만 다름) | `Lax`로 전송 | **적용됨** | ☐ |
| `lp.sshwan.com` → `api.sshwan.com` | 같음 | **다름** | `Lax`로 전송 | **적용됨** | ☐ |

> **이 표가 [ADR-018](decisions/ADR-018-single-registered-domain.md)의 요지다.** 사이트가 같아도 오리진이 다르면 CORS는 그대로 걸린다. "크로스사이트가 아니면 CORS도 없다"는 흔한 오해이고, 나도 처음에 그렇게 묶어서 잃는 시나리오를 4개로 과대평가했다. 실제로는 2개다.

---
## B. CORS

측정 환경: `lp.sshwan.com` → `api.sshwan.com` (same-site, **cross-origin**), Chromium, 2026-09-12.

### B-1. preflight 발생 조건 ✅ 실측

| 요청 | preflight | 결과 |
|---|---|---|
| `fetch` + `Content-Type: application/json` | **발생** | ✅ `OPTIONS /collect → 204` |
| `navigator.sendBeacon` (`text/plain` 고정) | 없음 | ✅ `POST` 만 기록됨 |
| 같은 `fetch` 를 10분 안에 재호출 | **없음** | ✅ `Access-Control-Max-Age: 600` 이 먹는다 |

엣지 로그(JSON)에서 메서드만 뽑은 것이다. 브라우저 DevTools 가 아니라 **서버 쪽 기록**이라 조작 여지가 없다.

```
OPTIONS /collect → 204     page_view (fetch) — 첫 요청이라 preflight
POST    /collect → 200     page_view 본 요청
POST    /collect → 200     click (fetch) — preflight 캐시되어 OPTIONS 없음
POST    /collect → 200     click (beacon) — 애초에 preflight 없음
```

> **세 번째 줄이 `Max-Age` 의 값어치다.** 이게 없으면 수집 한 건마다 왕복이 두 번이다.

---

### B-2. `Allow-Origin: *` 로 바꾸면 ✅ 실측 — **가장 중요한 발견**

`.env` 의 `CORS_ALLOW_ORIGIN_WILDCARD=true` 로 일부러 깨진 조합을 내보냈다.

**브라우저 콘솔 원문**

```
Access to fetch at 'https://api.sshwan.com/collect' from origin 'https://lp.sshwan.com'
has been blocked by CORS policy: The value of the 'Access-Control-Allow-Origin' header
in the response must not be the wildcard '*' when the request's credentials mode is 'include'.
```

여기까지는 예상대로다. **그런데 같은 시각 DB 를 보니 그 요청이 기록돼 있었다.**

```
id  event      transport  received_at
 7  page_view  fetch      2026-09-12 08:18:04.748   ← 브라우저가 "차단" 한 그 요청
```

| | |
|---|---|
| **CORS 는 요청을 막지 않는다** | 요청은 서버에 도달했고 처리됐다. 막히는 것은 **응답을 읽는 것**이다 |
| **스크립트는 이유를 모른다** | `catch` 에 온 것은 `TypeError: Failed to fetch` 뿐이다. CORS 라는 말이 없다 |
| **그래서 재시도하면 중복된다** | 클라이언트는 실패로 알고 다시 보내는데 서버에는 이미 들어가 있다 |

```js
// track.js 가 실제로 받은 것
[touchpoint] collect 오류 TypeError: Failed to fetch
```

> **이것이 이 실험의 진짜 산출물이다.** *"`*` 와 credentials 는 같이 못 쓴다"* 는 문서를 읽으면 안다. **그 설정으로 데이터가 어떻게 오염되는지**는 해 봐야 안다 — 수집은 되는데 클라이언트는 실패로 알고, 재시도가 붙으면 전환이 부풀려진다.
>
> 실무에서 이 조합은 "CORS 가 안 되네" 하고 `*` 로 바꿨다가 생긴다. 증상이 "데이터가 없음" 이 아니라 **"데이터가 두 배"** 라서 원인을 CORS 로 의심하지 않게 된다.

| 설정 | 결과 |
|---|---|
| `Allow-Origin: *` + `credentials: 'include'` | ✅ 브라우저 차단 · **서버는 기록함** |
| 정확한 오리진 + `Allow-Credentials: true` | ✅ 정상 |
| 오리진 반향 + `Vary: Origin` 누락 | ☐ 중간 캐시가 필요해 미측정. 정책 코드에는 `Vary` 를 강제하고 테스트로 못박아 뒀다 |

---

### B-3. `sendBeacon` 트레이드오프 ◐ 부분 실측

| 항목 | `fetch` | `sendBeacon` | 결과 |
|---|---|---|---|
| preflight | 발생 | 없음 | ✅ B-1 참조 |
| 커스텀 헤더 | 가능 | **불가** | ✅ `Content-Type` 도 `text/plain` 고정 |
| **CORS 오설정 시** | **차단** | **통과** | ✅ 아래 |
| 페이지 이탈 중 전송 | 취소될 수 있음 | **보장** | ☐ 100회 반복 측정 미실시 |
| 페이로드 크기 | 큼 | 약 64KB | ☐ |

**세 번째 줄이 예상 밖이었다.** B-2 의 와일드카드 상태에서 `fetch` 는 전부 막혔는데 `sendBeacon` 은 그대로 들어왔다.

```
id  event  transport  received_at                 상태
 8  click  beacon     2026-09-12 08:18:20.760     와일드카드 ON 중 — 통과
```

이유는 단순하다. **`sendBeacon` 은 응답을 읽지 않는다.** 읽지 않으므로 브라우저가 CORS 로 막을 이유가 없다. 반대로 말하면 **성공했는지 확인할 방법도 없다** — `navigator.sendBeacon()` 의 반환값은 "큐에 넣었다" 는 뜻이지 "서버가 받았다" 가 아니다.

> 트레이드오프가 이렇게 갈린다.
> **`fetch`** 는 결과를 알 수 있지만 CORS 에 걸리고 이탈 중 취소된다.
> **`sendBeacon`** 은 잘 나가지만 나갔는지 알 수 없다.
> 수집 파이프라인이 서버 큐(아웃박스)를 두는 이유가 여기에도 있다 — **클라이언트 전송은 어느 쪽이든 확인이 안 된다.**

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
| B-2 | `Allow-Origin: *` + credentials | 브라우저가 응답을 차단. **그런데 서버는 기록한다** → 재시도가 붙으면 전환이 부풀려짐 | 오리진을 정확히 반향 + `Vary: Origin`. 정책을 `src/Http/CorsPolicy` 로 빼고 테스트 16건으로 못박음 |
| B-3 | `sendBeacon` 헤더 불가 | 헤더를 못 붙이고 **성공 여부도 알 수 없다**. 대신 CORS 오설정에도 통과 | 두 경로를 모두 구현해 `transport` 로 구분 적재. 확인은 서버 큐가 담당 |
| C-1 | 301 캐시 | | |
| C-2 | 리다이렉트 파라미터 유실 | | |
| D-1 | 워커 중복 실행 | | |
| D-2 | 매체 타임아웃 | | |
| E-1 | 복제 지연 | | |
| E-2 | 보존기간 파기 | | |

---

## 하지 않기로 한 것 — A-1 · A-2

**등록 도메인을 1개로 정하면서 두 시나리오를 범위에서 뺐다** → [ADR-018](decisions/ADR-018-single-registered-domain.md)

| # | 시나리오 | 왜 못 하는가 |
|---|---|---|
| A-1 | 서드파티 쿠키 차단 (브라우저별 매트릭스) | 브라우저는 same-site를 **eTLD+1**로 판정한다. `lp.sshwan.com` → `api.sshwan.com`은 같은 사이트라 차단이 애초에 발동하지 않는다 |
| A-2 | `SameSite=None` 누락 | same-site 요청에는 `Lax`로 충분하다. `None`이 필요한 상황 자체가 만들어지지 않는다 |

### 남은 것은 무엇인가

수집 호스트를 `api.sshwan.com`에 두면 `lp.` → `api.` 는

- **same-site** (등록 도메인 동일) → 서드파티 쿠키 차단 없음
- **cross-origin** (서브도메인 상이) → **CORS는 그대로 적용**

그래서 **B-2와 B-3은 계속 재현된다.** preflight도, `Vary: Origin`도, `Allow-Origin: *`와 `credentials: include`의 충돌도 오리진 기준이지 사이트 기준이 아니기 때문이다.

> **이 구분을 정확히 아는 것 자체가 이 항목의 산출물이다.**
> "크로스사이트가 아니면 CORS도 없다"는 흔한 오해이고, 처음에는 나도 그렇게 묶어서 **잃는 시나리오를 4개로 과대평가했다.** 실제로는 2개다.

### 되돌리는 조건

추적 도메인이 생기면 `.env`의 `TRACK_DOMAIN` 한 줄 + DNS A 레코드 + certbot 재발급으로 붙는다. 엣지는 `TRACK_DOMAIN`이 비어 있어도 뜨도록 만들어 뒀고 CI가 두 경우를 모두 검사한다. **코드는 준비돼 있고 도메인만 없다.**
