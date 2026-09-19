# 결제 웹훅 구현 계획 — D-3 의 못 잰 두 줄

> 목적은 결제 기능이 아니다. [failure-scenarios D-3](failure-scenarios.md) 의 `✗ 구현 없음` 두 줄
> (**웹훅 중복 수신** · **순서 역전**)을 **재현하고 재는 것**이다.
> 그 둘을 재는 데 필요하지 않은 것은 만들지 않는다.
>
> 실제 PG 연동은 범위 밖이다 → [ADR-008](decisions/ADR-008-stub-pg.md) ·
> [ADR-016](decisions/ADR-016-payment-and-notification.md).
> **09-19 이후**: 실PG(이니시스)는 [plan-multi-pg.md](plan-multi-pg.md) 가 다룬다. 이 문서의 스텁·CAS 는
> 그대로 쓰이고, 실PG 결과도 같은 `applyEvent()` 로 들어간다. 12장 결정 5 의 "청구가 없다" 는 `pg=stub` 에서만 참이다.
> 스키마는 [20260909000400](../application/migrations/20260909000400_create_payments_coins.php) 에
> 이미 있다. **새로 만들지 않고 그것을 쓴다.**

## 0. 범위에서 뺀 것

| 뺀 것 | 이유 |
|---|---|
| `GatewayInterface` · PG 어댑터 2종 | ADR-016 이 *"구현체가 하나면 인터페이스가 옳은지 알 수 없다"* 고 이미 적었다. 이번에 만드는 `cli/pg` 는 **PG 흉내지 어댑터가 아니다** — 그 구분을 흐리지 않는다 |
| 환불·부분환불 | 전이표에는 넣되 **웹훅 처리는 구현하지 않는다.** 코인 lot 회수(ADR-006)가 붙어 D-7 에 안 들어간다. 축소가 아니라 순서다 |
| 결제 완료 알림 | ADR-016 의 Phase 2 |
| 로그인·인증 | `/signup` 이 없다 → 11장 |

---

## 1. 엔드포인트

### ① `POST app./purchase` — 결제 시작

규격은 [api-spec 6장](api-spec.md)에 이미 있다. 새로 정하지 않는다.

```json
{ "product": "coin_100", "amount_minor": 9900, "currency": "KRW",
  "idempotency_key": "...", "user_uid": "01J8XM…" }
```

| 필드 | 규칙 |
|---|---|
| `product` | 서버 상품표(`src/Payment/CoinProduct`)에 있는 코드만 |
| `amount_minor`·`currency` | **상품표와 대조한다.** 다르면 422. 클라이언트가 금액을 정하면 그건 결제 조작이다 |
| `idempotency_key` | 필수. `payments.uq_idem` 이 이중결제를 막는다 |
| `user_uid` | `users.user_uid` → `users.id` 조회. 인증이 없어 임시다 (11장) |

**한 트랜잭션**: `INSERT IGNORE INTO payments(status='created')` → `payment_events(from=NULL, to='created', source='api')`.

| 상황 | 코드 | 본문 |
|---|---|---|
| 신규 | 201 | `{payment_uid, status:"created"}` |
| 같은 `idempotency_key` | **200** | 기존 `payment_uid` + `duplicated:true` |
| 상품·금액 불일치 | 422 | Problem Details |

중복 판정은 `INSERT IGNORE` 의 `affected_rows` 로 하고, 기존 행은 **그 뒤에** 읽는다.
`Conversion_model::findByDedupKey()` 주석의 순서를 그대로 지킨다.

### ② `POST app./webhooks/pg` — 웹훅 수신

```
X-Pg-Signature: t=1757836800,v1=<hex64>

{ "event_id": "evt_01J…", "payment_uid": "…32 hex…", "status": "captured",
  "amount_minor": 9900, "currency": "KRW", "occurred_at": "2026-09-14T12:34:56.789Z" }
```

**호스트는 `app.`** (→ 12장 결정 1). `requireHost('app')`.

| 상황 | 코드 | 본문 | 왜 |
|---|---|---|---|
| 전이함 | 200 | `{"result":"applied","status":"captured"}` | |
| 무시 (과거·동일·종결) | **200** | `{"result":"ignored","status":"captured"}` | 2xx 가 아니면 PG 가 **영원히 재전송한다.** 무시는 성공이다 |
| 서명 불일치·시각 창 초과 | 401 | `invalid-signature` | |
| 본문 파손·모르는 `status` | 422 | `invalid-request` | 재전송해도 같다 |
| 모르는 `payment_uid` | 404 | `payment-not-found` | 재전송을 유도한다. 우리 커밋이 늦었을 수 있다 |

### ③ `cli/pg` — 스텁 PG (측정 장비)

```
cli/pg sign <payment_uid> <status>    # 서명된 본문+헤더를 출력만
cli/pg send <payment_uid> <status>    # 단건 전송 (순서 역전용)
```

**`sign` 이 핵심이다.** 동시성은 CLI 루프가 아니라 셸이 만든다(`xargs -P8`) —
[D-3](failure-scenarios.md) 의 동시 8개를 잰 방식 그대로여야 기존 결과와 비교된다.
그리고 `sign` 이 뱉은 본문을 그대로 8번 보내면 **바이트까지 동일한 재전송**이 된다.

---

## 2. 상태 머신

```
created → pending → authorized → captured → refunded
   └──────────┴───────────┘ → failed
```

| 상태 | rank | 종결 |
|---|---|---|
| `created` | 0 | |
| `pending` | 1 | |
| `authorized` | 2 | |
| `captured` | 3 | |
| `failed` | — | ✅ |
| `refunded` | — | ✅ |

### 전이표

| to | 허용된 from |
|---|---|
| `pending` | `created` |
| `authorized` | `created`, `pending` |
| `captured` | `created`, `pending`, `authorized` |
| `failed` | `created`, `pending`, `authorized` |
| `refunded` | `captured` (이번엔 웹훅 미구현) |

그 외 모든 조합은 **무시**다.

**건너뛴 전진을 허용하는 것이 이 표의 유일한 판단이다.** `captured` 가 `authorized`
보다 먼저 도착했을 때 "순서가 틀렸으니 무시" 하면 우리는 200 을 돌려주고, PG 는
재전송하지 않고, **결제 완료가 영원히 사라진다.** 과거로 가는 것만 막으면 된다.
건너뛴 사실은 `payment_events` 에 `from='created', to='captured'` 로 남는다.

---

## 3. 중복 수신을 무엇이 막는가

**DB 다. 단 UNIQUE 가 아니라 원자적 조건부 UPDATE(CAS)다.**

```sql
UPDATE payments
   SET status = ?, captured_at = IF(? = 'captured', ?, captured_at)
 WHERE id = ? AND status IN (<전이표의 허용 from>)
```

`affected_rows` 가 1 이면 전이, 0 이면 무시. **판정과 쓰기가 한 문장이라 틈이 없다.**

> [D-3](failure-scenarios.md) 이 확인한 함정이 결제에서는 이렇게 생긴다 —
> `SELECT status` 로 `authorized` 를 읽고 "그럼 `captured` 로 올려도 되겠다" 하고
> UPDATE 한다. 동시 여덟이 전부 `authorized` 를 읽으므로 **여덟 다 통과한다.**
>
> `conversions` 는 `uq_dedup` 이 있어 1행으로 막힌다. **`coin_lots` 에는 UNIQUE 가 없다.**
> 그래서 **매출은 한 번인데 코인이 여덟 배로 지급된다.** 증상이 "결제 오류" 가 아니라
> "재화 초과 지급" 이라 결제 로그를 봐도 안 보인다. 8장에서 재현해 보인다.

`payment_events.event_id` UNIQUE 로 막는 방법도 있으나 **이번엔 하지 않는다** —
스키마 변경이 필요하고, CAS 가 이미 같은 일을 하며, "같은 이벤트 재전송" 과
"다른 이벤트가 이미 상태를 올림" 은 결과가 같다(무시). `event_id` 는
`raw_payload` JSON 에 원문으로 남는다.

---

## 4. 순서 역전을 무엇이 막는가

**상태 서열이다. 타임스탬프가 아니다.**

| 후보 | 판정 |
|---|---|
| `occurred_at` 비교 | **기각.** ① 우리 시계와 PG 시계가 다르다 ② **재전송은 같은 타임스탬프를 갖는다** — 중복과 역전을 같은 기준으로 못 가른다 ③ `last_event_at` 컬럼이 필요 = 스키마 변경 |
| **상태 서열** | **채택.** 이미 있는 `status` 한 칸으로 판정된다. 2장 전이표가 그대로 `WHERE … IN (…)` 이 된다 |

`captured` 뒤에 `pending` 이 오면 — `pending` 의 허용 from 은 `('created')` 뿐이고
현재는 `captured` 이므로 `affected_rows = 0`. **과거가 미래를 덮지 못한다.**

`occurred_at` 은 `payment_events.raw_payload` 에 원문으로 남긴다.
**판정에는 안 쓰고 기록에는 쓴다.**

### 무시된 웹훅도 `payment_events` 에 남긴다

`from_status = to_status = 현재 상태` 로 append 한다 (→ 12장 결정 2).
읽는 쪽은 **`from_status <> to_status` 만 전이로 센다.**

---

## 5. 웹훅 서명

HMAC-SHA256. 스텁이라도 흉내는 낸다.

```
서명 대상:  <timestamp> + "." + <원본 바디 바이트 그대로>
헤더:       X-Pg-Signature: t=<unix>,v1=<hex>
비밀키:     .env 의 PG_WEBHOOK_SECRET
```

| 규칙 | 이유 |
|---|---|
| **원본 바이트로 서명한다.** 파싱한 배열을 재인코딩하지 않는다 | 키 순서·공백 하나로 서명이 깨진다 |
| `hash_equals()` 로 비교 | `===` 는 앞에서부터 끊겨 타이밍이 샌다 |
| `t` 가 ±300초 밖이면 거절 | 재생 공격 창을 닫는다 |
| 서명 실패는 **401**, 본문 파싱은 그 뒤에 | 서명 안 된 본문은 해석하지 않는다 |

판정은 `src/Payment/WebhookSignature` 에 (`CorsPolicy` 와 같은 자리). 시각은 주입받는다.

---

## 6. 전환은 어디서 발화하는가

**`captured` CAS 가 1행을 바꾼 그 트랜잭션 안이다.**

```
BEGIN
  UPDATE payments … WHERE status IN (…)     ← 0행이면 여기서 끝
  INSERT INTO payment_events (…)
  ── to='captured' 일 때만 ──
  INSERT INTO coin_lots (…)
  Conversion_model::createWithOutbox(…)
COMMIT
```

- `dedup_key = 'purchase:<payment_uid hex>'` (9 + 32 = 41자, 64 안)
- `value_minor`·`currency` 는 **`payments` 에서 읽는다.** 웹훅 본문의 금액을 쓰지 않는다 — 금액의 진실은 우리가 만든 결제 행이다
- **`conversions.user_id` 가 이 경로에서 처음 채워진다.** `Conversion.php` 주석이 표시해 둔 자리다
- `visit_id` 는 `users.signup_visit_id` 경유. **last-touch 가 아니라 가입 접점이다** (11장 ④)

CAS 가 0행이면 이 블록에 **도달하지 않는다.** `uq_dedup` 은 두 번째 방어선이다.

---

## 7. 코인 적립

**같은 트랜잭션이다.** 갈라지면 *"돈은 받았는데 코인이 없다"* 가 만들어지고,
그건 사용자가 먼저 발견한다.

```
coin_lots: kind='paid', source='purchase', amount = remaining = 상품표의 코인 수,
           payment_id = <이 결제>, expires_at = now + 5년   ← ADR-006
```

`coin_lots` 에 UNIQUE 가 없으므로 **중복 적립을 막는 것은 오직 CAS 다.**
이 사실이 8장 대조군의 관측 지점이 된다.

---

## 8. D-3 의 두 줄을 어떻게 재는가

**이 장이 이 작업의 목적이다.**

### 준비

```bash
php public/index.php cli/seed user
curl -XPOST https://app.sshwan.com/purchase -H 'Content-Type: application/json' \
  -d '{"product":"coin_100","amount_minor":9900,"currency":"KRW","idempotency_key":"d3-dup-1","user_uid":"<uid>"}'
php public/index.php cli/pg send <payment_uid> pending
php public/index.php cli/pg send <payment_uid> authorized
```

### ① 중복 수신 — 같은 `captured` 웹훅 동시 8개 (3회 반복)

```bash
php public/index.php cli/pg sign <payment_uid> captured > /tmp/hook.env
seq 1 8 | xargs -P8 -I{} sh -c 'curl -s -w "%{http_code} " -H "$SIG" \
  --data-binary @$BODY_FILE https://app.sshwan.com/webhooks/pg'
```

| 관측 | 기대 |
|---|---|
| 응답 분포 | `applied 1 · ignored 7` (전부 200) |
| `payments.status` / `captured_at` | `captured` / 1개 값, 이후 불변 |
| `payment_events` `from<>to` | **1행** |
| `payment_events` `from=to` | 7행 |
| `coin_lots WHERE payment_id=?` | **1행** |
| `conversions WHERE dedup_key='purchase:…'` | **1행** |
| 엣지 로그 8건의 수신 시각 폭 | **ms 단위여야 한다.** 벌어져 있으면 동시가 아니었다 (D-1 에서 배운 확인) |

### ①′ 대조군 — "먼저 SELECT 해서 막기" 를 켠다

`.env` 의 `PAYMENT_WEBHOOK_PRECHECK=true` (→ 12장 결정 3).

| 관측 | 예상 |
|---|---|
| `payment_events` 전이행 | **2행 이상** |
| `coin_lots` | **2행 이상** ← 여기가 사고다 |
| `conversions` | **1행** (`uq_dedup` 이 막는다) |

> 예상이 맞다면 산출물은 숫자가 아니라 **"매출은 한 번인데 코인이 두 번" 이라는
> 어긋남의 모양**이다. 결제 장부만 보면 정상이라 발견이 늦는다.
> 예상과 다르면 둘 다 적는다.

### ② 순서 역전 — `captured` 뒤에 `pending`

| 관측 | 기대 |
|---|---|
| 응답 | `200 {"result":"ignored","status":"captured"}` |
| `payments.status` · `captured_at` | **불변** |
| `payment_events` | `from='captured', to='captured'` +1 |
| `coin_lots` · `conversions` | 각 1행 그대로 |

변형 둘: `authorized` 를 뒤에 보내도 같은 결과 /
**새 결제**에 `captured` 를 먼저 보내면 `from='created'` 로 **전이한다**
(2장에서 건너뛴 전진을 허용한 이유가 여기서 관측된다).

### 끝나고

`failure-scenarios.md` D-3 의 두 줄, 요약표, `benchmarks.md`, `worklog.md` 반영.
**결과부터 적지 않는다 — 절차를 먼저 커밋하고 측정한다.**

---

## 9. 만들 파일

| 파일 | 역할 |
|---|---|
| `src/Payment/PaymentStatus.php` | 상태 상수·서열·종결 여부 |
| `src/Payment/PaymentStateMachine.php` | `allowedFrom(to)` 하나가 본체. 그 배열이 그대로 SQL 의 `IN` 목록이 된다 |
| `src/Payment/WebhookSignature.php` | HMAC 검증 + 재생 창 |
| `src/Payment/WebhookEvent.php` | 본문 검증·정규화 (`ConversionInput` 과 같은 모양) |
| `src/Payment/CoinProduct.php` | 상품 코드 → 코인 수·금액·통화 |
| `tests/Payment/*` | **전이표 전 칸을 도는 테스트.** 표에서 한 칸을 고치면 테스트가 깨져야 한다 |
| `application/models/Payment_model.php` | `createIfAbsent()` · `findByUid()` · **`applyEvent()`** (CAS + 이벤트 + 부수효과를 한 트랜잭션에) |
| `application/models/Coin_model.php` | `grantPurchaseLot()` |
| `application/controllers/Purchase.php` | `POST /purchase` |
| `application/controllers/Webhook.php` | `POST /webhooks/pg` |
| `application/controllers/cli/Pg.php` | 스텁 PG |

**고칠 기존 파일**: `routes.php`(웹훅 한 줄) · `.env.example`(비밀키·창·대조군 스위치) ·
`cli/Seed.php`(`user()`) · `api-spec.md`(웹훅 절 추가) · 측정 후 문서 3종

---

## 10. 작업 순서

| # | 단계 | 검증 |
|---|---|---|
| 1 | `src/Payment/*` + 테스트 | `phpunit` — 전이표 전 칸, 서명 위조·시각 창, 본문 거절 사유 |
| 2 | `Payment_model`(CAS) · `Coin_model` | CLI 에서 `applyEvent` 를 **직접 두 번** 호출 — 두 번째가 0행인지 |
| 3 | `Purchase` + 라우트 + `.env` + `cli/seed user` | 201 / 같은 키 200 / 금액 불일치 422 / `lp./purchase` 404 |
| 4 | `Webhook` | 정상 200, 위조 서명 401, 오래된 `t` 401, 모르는 uid 404 |
| 5 | `cli/pg` | `sign` 출력물을 그대로 `curl` 에 먹여 200 — **장비가 먼저 맞아야 측정이 성립한다** |
| 6 | **8장 측정** | 3회 반복 |
| 7 | 문서 갱신 | 측정 원문을 그대로 붙인다 |

1~2 는 서버 없이 끝난다. 3~5 에서 처음 배포가 필요하다.

---

## 11. 위험 · 모르는 것

### 확인한 것

**① CI3 의 중첩 트랜잭션에서 안쪽 `trans_rollback()` 은 롤백하지 않는다.**

```php
// vendor/.../DB_driver.php:970
// When transactions are nested we only begin/commit/rollback the outermost ones
elseif ($this->_trans_depth > 1 OR $this->_trans_rollback())
{
    $this->_trans_depth--;
    return TRUE;      // ← 아무것도 되돌리지 않고 참을 돌려준다
}
```

`Payment_model` 의 트랜잭션 안에서 `createWithOutbox()` 를 부르면 그 안의 중복
롤백은 **아무것도 되돌리지 않는다.** 지금 구조에서는 그 지점까지 아무것도 INSERT
되지 않았으므로 안전하지만, **우연히 안전한 것**이다. `createWithOutbox` 에 앞쪽
쓰기가 한 줄이라도 추가되면 조용히 깨진다. 모델 주석에 적어 둔다.

**② `db_debug` 가 켜져 있으면 쿼리 오류에서 CI3 가 HTML 에러 화면을 뱉는다.**
웹훅 응답이 JSON 이 아니게 되고 PG 는 그걸 5xx 취급해 재전송한다.

**③ 인증이 없다.** `/purchase` 가 본문의 `user_uid` 를 그대로 믿는다 (→ 12장 결정 5).

**④ `payments` 에 `visit_id` 가 없다.** 결제 전환의 유입 귀속이
`users.signup_visit_id` 경유뿐이라 **가입 접점에 귀속된다 — last-touch 가 아니다.**
[C-2](failure-scenarios.md) 가 남긴 *"흡수된 방문자2 의 결제가 c2B 로 귀속되는가"* 는
이 계획으로도 끝까지 못 간다.

### 모르는 것 [추정]

| | |
|---|---|
| 실제 PG 가 **재전송 시 서명을 다시 만드는지** | 확인한 적 없다. 재생 창과 충돌할 수 있다. 스텁에서는 안 드러난다 |
| 동시 8개가 CAS 행 잠금에서 **얼마나 기다리는지** | 트랜잭션 안에 `conversions`·`outbox` 쓰기가 있어 **D-1 의 마이크로초보다 길다.** 측정하면 곁가지 산출물이 된다 |
| 반나절 안에 끝나는지 | 1~5 는 `/conversion` 규모이나 **6(측정)이 D-3 에서 그랬듯 예상보다 길다.** 부족하면 대조군(①′)을 다음으로 미룬다 |

---

## 12. 메인의 결정 (2026-09-14)

설계안은 서브에이전트가 냈고, 아래 다섯은 메인이 정했다.
**전제는 전부 코드·스키마로 확인한 뒤 승인했다.**

### 결정 1 — 웹훅 호스트는 `app.` ✅ 제안대로

`api.` 는 **브라우저 수집 전용**이고 `CorsPolicy` 가 붙어 있다. 서버 간 호출인
웹훅을 거기 두면 [A-3·B-1](failure-scenarios.md)의 CORS 측정에 성격이 다른
트래픽이 섞인다. 결제는 `app.` 에 있으므로 결제 웹훅도 `app.` 이다.

### 결정 2 — 무시된 웹훅을 `from=to` 로 남긴다 ✅ 제안대로

**측정 가능성이 관습을 이긴다.** 무시를 로그에만 남기면 D-3 결과를 `grep` 으로
세야 하고, 그건 재현 가능한 숫자가 아니다. 중복 시험의 `ignored 7` 은
**그 7행 자체가 증거**다.

대가(읽는 쿼리가 `from <> to` 를 빠뜨리면 전이 횟수를 틀리게 센다)는 실재하므로
**`Payment_model` 의 docblock 에 그 규칙을 못박는다.** 스키마 변경이 없다는 것도
확인했다 — `payment_events.from_status` 는 이미 `NULL` 허용 `VARCHAR(24)` 다.

### 결정 3 — 대조군 스위치를 넣는다 ✅ 제안대로, 강하게

*"잘못된 코드 경로를 저장소에 남기는 일"* 이라는 우려는 맞다. 그런데 이 저장소는
**이미 그 방식으로 최고의 발견들을 만들었다.**

```
CORS_ALLOW_ORIGIN_WILDCARD   B-2 — 가장 중요한 발견
OUTBOX_SKIP_LOCKED           D-1 — 예상이 틀린 곳
BRIDGE_REDIRECT_STATUS       C-1 — 301 은 롤백이 없다
NOOP_OUTCOME                 D-2 — 실패 경로를 처음 끝까지
```

**끈 쪽을 돌려 봐야 켠 쪽이 얼마를 버는지 말할 수 있다.** 그리고 이번 대조군의
산출물이 그중 제일 선명하다 — **매출은 한 번인데 코인이 여덟 배.**
기본값 `false`, 운영에서 켤 이유 없음을 주석에 명시한다.

### 결정 4 — `cli/seed user` 를 추가한다 ✅

선택이 아니다. `payments.user_id` 가 `NOT NULL` + `fk_payments_user` 라
**users 행이 없으면 `/purchase` 자체가 FK 로 막힌다.** 확인했다.

### 결정 5 — `/purchase` 무인증을 유지하되 명시한다 ✅

인증 기반이 없고(`/signup` 미구현) D-7 에 만들 수 없다. 다만 **악용 범위를
정확히 적는다.**

> 누구나 임의의 `user_uid` 로 **`payments` 행(status=`created`)을 만들 수 있다.**
> 그러나 **전환도 코인도 만들 수 없다** — 그 둘은 `captured` 웹훅에서만 발화하고,
> 웹훅은 **HMAC 서명**을 요구한다. 즉 **서명이 GA4 오염을 막는 실질 방어선**이다.
> 스텁 PG 라 실제 청구도 없다.
>
> **09-19 이후**: 공개 시연 버튼(`/pay/stub/confirm`)이 서버 대신 서명한다. 그 버튼이 방금 만든 `demo-`
> 결제는 누구나 captured 로 만들 수 있고 그 전환은 매체로 간다(사용자 결정) → [plan-multi-pg.md](plan-multi-pg.md) 6장

README 의 "아직 없는 것" 과 `api-spec.md` 에 이대로 적는다.
**이건 감수가 아니라 미구현이다.**

### 추가 지시

- **11장 ①(중첩 트랜잭션)을 `Payment_model` 과 `Conversion_model` 양쪽 주석에** 남긴다.
  한쪽만 적으면 다른 쪽을 고치는 사람이 못 본다 — 오늘 갱신한 지침 규칙 7 그대로다
- 측정 전에 **`db_debug` 상태를 확인**한다 (11장 ②)
- 시간이 부족하면 **대조군(①′)이 아니라 문서 갱신을 미룬다.** 측정값은 휘발하지만
  문서는 나중에 쓸 수 있다
