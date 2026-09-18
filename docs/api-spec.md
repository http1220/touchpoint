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
| 3 | POST | `/collect` | **`api.sshwan.com`** | 크로스사이트 수집 |
| 4 | POST | `/conversion` | **`api.sshwan.com`** | 전환 등록 |
| 3-1 | POST | `/impression` | **`api.sshwan.com`** | 배너 노출 배치 (09-15) |
| 3-2 | GET | `/click` | **`api.sshwan.com`** | 배너 클릭 기록 후 302 (09-15) |
| 5 | POST | `/signup` | `app.sshwan.com` | 가입 |
| 6 | POST | `/purchase` | `app.sshwan.com` | 코인 결제(기본 스텁 · `pg=inicis` 는 시연 토큰 필요) |
| 6-1 | GET·POST | `/pay`, `/pay/inicis/*`, `/pay/result/{uid}` | `app.sshwan.com` | 실PG(이니시스 테스트 상점) 결제 — 시연 토큰 (09-19) |
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

**`lp.sshwan.com` → `api.sshwan.com`** 은 **same-site 이면서 cross-origin** 이다. 사이트가 같으므로 쿠키는 `Lax` 로 전송되고, 오리진이 다르므로 **CORS 는 그대로 걸린다** → [ADR-018](decisions/ADR-018-single-registered-domain.md)

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
Host: api.sshwan.com
Origin: https://lp.sshwan.com
Content-Type: application/json
Cookie: ab_tid=...          ← same-site 라 SameSite=Lax 로 전송됨

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
Set-Cookie: ab_tid=...; Domain=.sshwan.com; SameSite=Lax; Secure; HttpOnly

{ "ok": true }
```

### 실험: `sendBeacon` 경로 비교

| 방식 | Content-Type | preflight | 커스텀 헤더 | 이탈 중 전송 |
|---|---|---|---|---|
| `fetch` + JSON | `application/json` | **발생** | 가능 | 취소될 수 있음 |
| `navigator.sendBeacon` | `text/plain` | **없음** | **불가** | **보장** |

> 트레이드오프: preflight를 피하면 헤더를 못 붙인다. `track.js`에 두 경로를 모두 구현하고 `failure-scenarios.md`에 결과를 표로 남긴다.

---

## 3-1. `POST api./impression` — 배너 노출 배치

```json
{ "visit_uid": "01J8XK...(선택)", "items": [ { "work_id": 3, "slot": "lp_related" }, … ] }
```

| 규칙 | 이유 |
|---|---|
| **화면에 절반 이상 보인** 배너만 (track.js · IntersectionObserver) | 그려진 것을 세면 스크롤되지 않은 배너까지 노출이 되어 CTR 이 낮게 나온다 |
| 한 요청에 모아 보낸다. 이탈 시 `sendBeacon`(text/plain) | 배너마다 요청하면 페이지 하나에 수십 요청 |
| 틀린 항목만 버리고 나머지를 받는다 · 최대 50 · 배치 안 중복 1회 | beacon 은 응답을 읽을 쪽이 없다. 422 로 전체를 거절하면 조용히 전부 잃는다 |
| `items` 자체가 없거나 배열이 아닐 때만 422 | |
| CORS 는 `/collect` 와 같은 판정 | 한쪽만 달라지면 track.js 의 한 경로만 조용히 막힌다 |

응답 `200 {"accepted": N, "dropped": M}` → `src/Collect/ImpressionBatch.php`

## 3-2. `GET api./click?w=&s=&sd=&u=` — 배너 클릭

기록하고 `u` 로 **302**. GET 인데 상태를 바꾼다(RFC 9110 safe method 위반) — 링크여야 해서다. 그 대가를 막는 방법:

| 문제 | 막는 방법 |
|---|---|
| 프리페치·크롤러·미리보기가 누른다 | 봇 UA · 빈 UA · `HEAD` 는 기록하지 않는다 |
| 새로고침·뒤로 가기로 두 번 | `(방문, 작품, 자리, sd)` 해시를 `UNIQUE` — `INSERT IGNORE` |
| 중간 캐시가 302 를 재사용 | `Cache-Control: no-store` |
| 목적지를 바꿔 피싱(오픈 리다이렉트) | `u` 는 **우리 도메인 https** 만. 아니면 400 — 이동을 막는 유일한 경우 |
| 방문 쿠키 없는 클릭 | 방문을 만들지 않고 기록 없이 이동. 유입 없는 방문이 분모를 오염시킨다 |

**`sd` 는 노출일이다.** 링크를 그린 서버가 박는다. 23:59 에 본 배너를 00:01 에 누르면 클릭 시각 기준으로는 노출·클릭이 다른 날로 갈라져 두 날 CTR 이 모두 틀린다. 조작 가능한 값이라 오늘 기준 −7일~+1일 밖이면 오늘로 바꾼다.

**기록이 실패해도 이동은 실패하지 않는다** — 봇·중복·방문 없음 모두 302 → `src/Collect/ClickRequest.php`

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
  "dedup_key": "payment:01J8XP...",
  "page_url": "https://lp.example.com/works/8733"
}
```

| 필드 | 규칙 |
|---|---|
| `type` | `signup` \| `purchase` \| `subscribe` |
| `value_minor` | **정수**. KRW는 소수 0자리라 `9900` = 9,900원 |
| `currency` | ISO 4217 3글자 |
| `dedup_key` | **UNIQUE**. 같은 키 재요청은 기존 결과 반환 |
| `page_url` | 선택. 전환이 일어난 페이지. 크로스오리진이라 `Referer` 에 경로가 없어 본문으로 받는다. **우리 도메인 https 만 받고 쿼리·조각은 버린다.** 틀리면 422 가 아니라 조용히 버린다 — 광고 부가 정보 때문에 전환을 잃지 않는다 |

> 브라우저가 부른 요청(`Origin` 이 우리 도메인)이면 UA·IP·`_fbp`·`_fbc` 도 매체 전송용으로 싣는다. 서버 간 호출에는 싣지 않는다 — 그 UA·IP 는 서버의 것이다 → [ADR-005 「결정」](decisions/ADR-005-channel-adapter.md)

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
| `refunded` 시 남은 코인 회수 · `refund` 전환 적재 (09-15) | GA4 는 원래 구매의 `transaction_id` 로 `refund`. Meta 는 표준 환불 이벤트가 없어 적재하지 않는다. **쓴 코인은 음수로 깎지 않고** 응답 `coins_spent` 와 error 로그로 남긴다 |
| **캡처 전에 도착한 `refunded` 는 409** | 무시(200)하면 PG 가 재전송하지 않고, 뒤이어 온 `captured` 가 결제를 살려 둔다. 캡처 뒤 재전송에서 처리된다 |
| 롤백(DB 오류)은 **503 + `Retry-After`** | 09-15 까지 200 `ignored` 로 나가 PG 가 재전송하지 않았다 |

> PG는 스텁이다. **성공/실패/지연/중복 웹훅을 시나리오로 주입**할 수 있게 만들어 상태 머신과 멱등성만 진짜로 검증한다 → [ADR-008](decisions/ADR-008-stub-pg.md)

### 선택 필드 `pg` (09-19)

| 값 | 조건 | 거절 |
|---|---|---|
| 없음 · `stub` | 없음 (기존 그대로) | — |
| `inicis` | 시연 토큰 쿠키 `tp_pay` · 이니시스 설정 · 통화 KRW | 403 `pay-locked` · 503 `pg-unavailable` · 422 |

결제 행을 만들기 **전에** 검사한다. 받을 수 없는 결제의 행이 남으면 오래된 결제 보고에 영원히 걸린다.

---

## 6-1. 실PG — 이니시스 테스트 상점 (카드) → [plan-multi-pg.md](plan-multi-pg.md)

| 메서드 | 경로 | 문 | 하는 일 |
|---|---|---|---|
| GET | `app./pay?t=<토큰>` | 토큰 | 쿠키 `tp_pay` 를 걸고 `303 /pay` (주소에서 토큰을 지운다) |
| GET | `app./pay` | 쿠키 | 시연 결제 화면. 없으면 **404** — 잠긴 문이 있다는 것도 알리지 않는다 |
| POST | `app./pay/inicis/start` `{payment_uid}` | 쿠키 | 결제창 필드에 **서버가** 서명해 돌려준다 (`signature` · `verification` · `mKey`) |
| POST | `app./pay/inicis/return` | **이니시스 흐름** | 이니시스가 브라우저를 통해 보낸다 — 쿠키가 실리지 않는다(교차 사이트 POST). 승인 → 장부 → `303 /pay/result/{uid}` |
| GET·POST | `app./pay/inicis/close` | — | 결제창 닫기 |
| GET | `app./pay/result/{uid}` | 쿠키 | 결제 상태와 장부 이벤트 |

### `return` 이 장부에 적는 것

| 이니시스 결과 | 장부 | 이유 |
|---|---|---|
| 승인 + 금액·주문 일치 | `captured` (source=`return`) + `payment_pg_refs.tid` | 같은 트랜잭션 |
| 승인됐는데 **장부에 못 올림** (DB 오류 · 동시 복귀) | **망취소** 후 `failed` | 이 요청의 승인은 이 요청이 되돌린다 |
| 승인 응답 없음 · 읽을 수 없음 | **망취소** 후 `failed` | 결과 불명 — 승인이 났을 수 있다 |
| 승인은 났는데 금액·주문번호 불일치 | **망취소** 후 `failed` | 받지 않는다 |
| PG 가 승인 거절 | `failed` | 되돌릴 승인이 없다 |
| 승인 요청 **전에** 거절 (authUrl 호스트 · mid · 주문번호 · 인증 실패 코드) | **바꾸지 않는다** | 근거가 브라우저 입력이다. `failed` 로 적으면 위조 요청 하나로 남의 결제를 실패시킬 수 있다 |

환불은 웹이 아니라 CLI 다 — `cli/pg refund <uid>` (INIAPI 전체취소 → `refunded`, source=`admin`).

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
  "type": "https://sshwan.com/problems/invalid-request",
  "title": "invalid-request",
  "status": 422,
  "detail": "currency 는 ISO 4217 세 글자여야 합니다.",
  "field": "currency",
  "trace_id": "8fbeb057fd48847a9c92187550153ed3",
  "instance": "/conversion"
}
```

| 상황 | status | `type` |
|---|---|---|
| 검증 실패 | 422 | `invalid-request` |
| 오리진 미허용 | 403 | `origin-not-allowed` |
| `visit_uid` 없음 | 404 | `visit-not-found` |
| 중복 판정인데 기존 행이 없음 | 409 | `conversion-conflict` |
| 매체 전송 실패(내부) | — | 아웃박스에 기록, 응답엔 노출 안 함 |

> **`type` 은 상황별로 쪼개지 않는다.** 검증 실패는 전부 `invalid-request` 이고,
> 어느 필드인지는 RFC 9457 확장 멤버 `field` 로 붙인다. 경로는 `/problems/` 다
> — 이 값은 `MY_Controller::problem()` 이 만든다.
>
> `409` 는 **중복인데 기존 전환을 못 찾은** 경우에만 쓴다. "중복이라 성공(200)"
> 과 구분되는 진짜 충돌이라, 없는 uid 를 지어내지 않고 실패로 낸다.
