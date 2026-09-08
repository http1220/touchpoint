# API 명세

- 에러 응답은 전부 **RFC 9457 Problem Details** (`application/problem+json`)
- 모든 시각은 **ISO 8601 UTC**. 표시용 문자열을 내려주지 않는다
- 금액은 **정수 minor unit** + `currency` (ISO 4217)

---

## 엔드포인트 목록

| # | 메서드 | 경로 | 호스트 | 역할 |
|---|---|---|---|---|
| 1 | GET | `/go` | `lp.sshwan.com` | 브리지 리다이렉트 |
| 2 | GET | `/l/{work}` | `lp.sshwan.com` | 랜딩 |
| 3 | POST | `/collect` | **`api.khan-edge.com`** | 크로스사이트 수집 |
| 4 | POST | `/conversion` | **`api.khan-edge.com`** | 전환 등록 |
| 5 | POST | `/signup` | `app.sshwan.com` | 가입 |
| 6 | POST | `/purchase` | `app.sshwan.com` | 코인 결제(스텁) |
| 7 | GET | `/metrics` | `app.sshwan.com` | 지표 화면 |

---

## 1. `GET lp./go` — 브리지 리다이렉트

플랫폼 A의 `/webtoon/bridge/type/2/toon/8733` → `/webtoon/detail/...` 패턴을 그대로 따른다.

### 요청

```
GET /go?work=8733&pid=google&subpid=2609_romance_a&channel=search
        &utm_source=google&utm_medium=cpc&utm_campaign=romance_sep
        &gclid=EAIa...
Host: lp.sshwan.com
```

| 파라미터 | 필수 | 설명 |
|---|---|---|
| `work` | ✅ | 작품 ID. 목적지 결정 |
| `pid` / `subpid` / `channel` | — | 매체 분류 3단 |
| `utm_*` | — | 표준 UTM 5종 |
| `gclid` / `fbclid` | — | 매체 클릭 ID |

### 처리

1. `ab_vid` 쿠키 확인 → 없으면 `visits` 생성 + UUIDv7 발급
2. `touchpoints` 기록 — **`position='first'`는 없을 때만 생성**(first-touch 보존), `position='last'`는 매번 갱신
3. `work` → 최초 회차 조회
4. **302** 리다이렉트

### 응답

```
HTTP/1.1 302 Found
Location: https://lp.sshwan.com/l/8733?vid=01J8XK...
Set-Cookie: ab_vid=01J8XK...; Domain=.sshwan.com; Path=/;
            SameSite=Lax; Secure; HttpOnly; Max-Age=31536000
Cache-Control: no-store
```

### 실험: 파라미터 전달 방식 2가지 비교

| 방식 | Location | 장점 | 단점 |
|---|---|---|---|
| **A. 전량 전달** | `/l/8733?utm_source=..&gclid=..` | 목적지에서 바로 읽힘 | URL 오염, 길이 제한, **사용자가 공유하면 남의 유입이 내 클릭으로** |
| **B. `visit_uid`만** | `/l/8733?vid=01J8XK...` | 깔끔, 변조 불가 | 목적지가 서버 조회 필요 |

> **B를 기본으로 채택**하고 A는 `?mode=passthru`로 남겨 비교 가능하게 한다. B가 안전한 이유를 `failure-scenarios.md`에서 재현한다.

### 실험: 301 vs 302

| 코드 | 브라우저 동작 | 결과 |
|---|---|---|
| **302** Found | 매번 서버에 물어봄 | ✅ 목적지 변경 가능, 클릭 집계 가능 |
| **301** Moved Permanently | **영구 캐시** | ❌ 목적지를 바꿔도 반영 안 됨, **클릭이 서버에 안 옴** |

> 광고 링크에 301을 쓰면 캠페인 목적지를 바꿀 수 없고 클릭 수가 사라진다. `Cache-Control: no-store`를 함께 보내는 이유.

---

## 2. `GET lp./l/{work}` — 랜딩

작품 페이지. `track.js` 스니펫이 로드되어 3번(`/collect`)을 호출한다.

```
GET /l/8733
Host: lp.sshwan.com
```

응답은 HTML. `<script src="/track.js">` 포함.

---

## 3. `POST api./collect` — 크로스사이트 수집 ★ 핵심

**`lp.sshwan.com` → `api.khan-edge.com`** 이므로 **cross-site + cross-origin**이다. 여기서 이 프로젝트의 모든 문제가 발생한다.

### preflight

`Content-Type: application/json`은 단순 요청이 아니므로 **OPTIONS가 먼저 뜬다.**

```
OPTIONS /collect
Origin: https://lp.sshwan.com
Access-Control-Request-Method: POST
Access-Control-Request-Headers: content-type
```

```
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: https://lp.sshwan.com    ← * 아님
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: POST, OPTIONS
Access-Control-Allow-Headers: Content-Type
Access-Control-Max-Age: 600
Vary: Origin
```

> **`Allow-Origin: *`와 `Allow-Credentials: true`는 같이 못 쓴다.** 쿠키를 보내려면 오리진을 정확히 반향해야 하고, 반향하면 **`Vary: Origin`이 필수**다 — 빠뜨리면 CDN이 남의 오리진 응답을 준다.

### 본 요청

```
POST /collect
Host: api.khan-edge.com
Origin: https://lp.sshwan.com
Content-Type: application/json
Cookie: ab_tid=...          ← SameSite=None; Secure; Partitioned 이어야 전송됨

{
  "visit_uid": "01J8XK...",
  "event": "page_view",
  "work_id": 8733,
  "occurred_at": "2026-09-07T12:34:56.789Z"
}
```

```
HTTP/1.1 200 OK
Access-Control-Allow-Origin: https://lp.sshwan.com
Access-Control-Allow-Credentials: true
Vary: Origin
Set-Cookie: ab_tid=...; Domain=.khan-edge.com; SameSite=None; Secure;
            HttpOnly; Partitioned; Max-Age=31536000

{ "ok": true }
```

### 실험: `sendBeacon` 경로 비교

| 방식 | Content-Type | preflight | 커스텀 헤더 | 이탈 중 전송 |
|---|---|---|---|---|
| `fetch` + JSON | `application/json` | **발생** | 가능 | 취소될 수 있음 |
| `navigator.sendBeacon` | `text/plain` | **없음** | **불가** | **보장** |

> 트레이드오프: preflight를 피하면 헤더를 못 붙인다. `track.js`에 두 경로를 모두 구현하고 `failure-scenarios.md`에 결과를 표로 남긴다.

---

## 4. `POST api./conversion` — 전환 등록

```
POST /conversion
Content-Type: application/json

{
  "visit_uid": "01J8XK...",
  "user_uid": "01J8XM...",
  "type": "purchase",
  "value_minor": 9900,
  "currency": "KRW",
  "dedup_key": "payment:01J8XP..."
}
```

| 필드 | 규칙 |
|---|---|
| `type` | `signup` \| `purchase` \| `subscribe` |
| `value_minor` | **정수**. KRW는 소수 0자리라 `9900` = 9,900원 |
| `currency` | ISO 4217 3글자 |
| `dedup_key` | **UNIQUE**. 같은 키 재요청은 기존 결과 반환 |

### 응답

| 상황 | 코드 | 본문 |
|---|---|---|
| 신규 | `201` | `{"conversion_uid": "...", "dispatched_channels": ["ga4","meta"]}` |
| **중복** | `200` | 기존 `conversion_uid` — **에러가 아니다.** 멱등 |
| 검증 실패 | `422` | Problem Details |

> 중복을 `409`가 아니라 `200`으로 돌려주는 이유: 클라이언트 재시도는 **정상 동작**이다. 에러로 만들면 호출자가 불필요한 예외 처리를 하게 된다.

### 처리 (한 트랜잭션)

```sql
BEGIN;
  INSERT INTO conversions (...) ;                    -- dedup_key UNIQUE
  INSERT INTO dispatch_outbox (conversion_id, channel, payload, ...)
    VALUES (?, 'ga4', ...), (?, 'meta', ...);        -- 채널 수만큼
COMMIT;
```

> **전환과 아웃박스가 같은 트랜잭션에서 커밋된다.** 이것이 Redis·SQS를 쓰지 않는 이유다 → [ADR-003](decisions/ADR-003-mysql-outbox.md)

---

## 5. `POST app./signup` — 가입

```json
{ "email": "...", "password": "...", "visit_uid": "01J8XK..." }
```

처리:

1. `users` 생성 (Argon2id 해시)
2. **유입 경로를 `users`에 스냅샷 복사** — `signup_pid`, `signup_subpid`, `signup_channel`, `signup_utm_*`
3. `identities` 연결 (visit ↔ user)
4. `conversions(type='signup')` + 아웃박스 적재

> **스냅샷이 핵심이다.** `touchpoints`는 3개월 후 파기되지만 `users`는 5년 남는다. 플랫폼 A 개인정보처리방침이 "가입 경로"를 수집 항목으로 명시한 것과 같은 구조다.

---

## 6. `POST app./purchase` — 코인 결제 (스텁 PG)

```json
{ "product": "coin_100", "amount_minor": 9900, "currency": "KRW",
  "idempotency_key": "..." }
```

### 상태 전이

```
created → pending → authorized → captured
                        ↓            ↓
                     failed      refunded
```

| 규칙 | 이유 |
|---|---|
| 전이는 **단방향**, 역행 금지 | 웹훅 순서 역전 방어 |
| 전이마다 `payment_events` 에 append | 감사 추적 |
| **전환은 `captured`에서만 발화** | `created`에 보내면 실패 건도 전환으로 집계된다 |
| `captured` 시 `coin_lots` 적립 | `kind='paid'`, `expires_at = +5년` |

> PG는 스텁이다. **성공/실패/지연/중복 웹훅을 시나리오로 주입**할 수 있게 만들어 상태 머신과 멱등성만 진짜로 검증한다 → [ADR-008](decisions/ADR-008-stub-pg.md)

---

## 7. `GET app./metrics` — 지표 화면

| 지표 | 정의 | 품질 특성 (ISO 25010) |
|---|---|---|
| 어트리뷰션 보존율 | 어트리뷰션이 남은 방문 ÷ 전체 방문 | 기능 적합성 |
| 매체 전송 성공률 | 채널별 성공 ÷ 시도 | 신뢰성 |
| 구간별 소요 시간 | `parse_ms` / `db_ms` / `send_ms` p50·p95 | 성능 효율성 |
| 재시도 후 최종 성공률 | 백오프 회복률 | 신뢰성 |
| 중복 전환 차단 건수 | `dedup_key` 충돌 수 | 기능 적합성 |

> **이 화면은 읽기 커넥션(replica)을 쓴다.** 복제 지연을 주입하면 방금 기록한 전환이 여기 안 나타난다 → [ADR-007](decisions/ADR-007-read-write-split.md)

---

## 8. 에러 응답 (RFC 9457)

```
HTTP/1.1 422 Unprocessable Content
Content-Type: application/problem+json

{
  "type": "https://sshwan.com/probs/invalid-currency",
  "title": "Invalid currency",
  "status": 422,
  "detail": "currency must be an ISO 4217 alpha-3 code",
  "instance": "/conversion"
}
```

| 상황 | status | `type` |
|---|---|---|
| 검증 실패 | 422 | `invalid-request` |
| 오리진 미허용 | 403 | `origin-not-allowed` |
| `visit_uid` 없음 | 404 | `visit-not-found` |
| 매체 전송 실패(내부) | — | 아웃박스에 기록, 응답엔 노출 안 함 |
