# 멀티 PG 연동 계획 — 두 번째 PG 가 깨는 자리와 숨은 리스크

> [ADR-016](decisions/ADR-016-payment-and-notification.md) 이 비워 둔 `PgAGateway`·`PgBGateway` 자리를
> **이니시스(국내)·페이팔(해외)** 로 채우는 계획이다. 목적은 결제 기능이 아니라
> ADR-016 이 적어 둔 그대로 — **"두 번째를 붙일 때 무엇이 깨지는지를 기록하는 것"** 이다.
>
> 작성 2026-09-19 · 선행 문서: [plan-payment-webhook.md](plan-payment-webhook.md) (스텁 PG · CAS · D-3 측정)

## 0. 출발점 — 가정 시나리오 (축자)

> 기술적 주도권 및 데이터 내재화 (Lock-in 우려)
> 중간 플랫폼 의존성 및 장애 리스크
> 글로벌 세금 및 정산(Settlement) 정산 구조의 복잡성
> 추가적인 수수료 부담
> 위 사유로 통합pG솔루션을 사용하지 않고
>
> 코인 구입을 위한 멀티 PG 시스템 개발 필요
>
> 연동할 pg사 : (해외유저용)페이팔, (국내 이용자용)이니시스
>
> 숨은 리스크?
>
> 구현과제
> PG사별 맞춤형 API 연동 코드 개발 / 통합 결제 어드민(Admin) 및 대시보드 개발 / 복잡한 에러 핸들링 및 예외 처리 시스템 / PG사별 웹훅(Webhook) 수신 및 파싱 엔진 구현 / 각기 다른 통화/결제 수단별 분기 처리 로직

**지금 있는 것** (운영 중): 스텁 PG(`cli/pg`) · `/purchase` · `/webhooks/pg`(HMAC) · CAS 상태 머신 · 환불(09-15) · 코인 lot · 결제→전환→아웃박스→GA4.

표기: **[확인-문서]** 공식 문서로 확인 / **[확인-코드]** 이 저장소에서 확인 / **[확인-계산]** 로컬에서 다시 계산 / **[추정]** 착수 전(3장 0단계)에 검증

---

## 1. 숨은 리스크

### A. 시나리오의 사유가 뒤집히는 자리

| 사유 | 숨은 리스크 |
|---|---|
| 세금·정산 복잡성 | PG를 직접 붙이면 **우리가 판매자(seller of record)**가 된다. 해외 B2C 디지털 재화의 현지 소비세(EU VAT 등)를 신고·납부할 책임이 우리에게 온다. 통합 오케스트레이션(PortOne류)은 원래 이 짐을 지지 않고, 지는 것은 MoR(판매 대행)형이다. **"통합 PG"가 어느 쪽을 뜻하는지에 따라 이 사유가 반대로 뒤집힌다** [추정 — 세무 판단은 범위 밖] |
| 중간 플랫폼 장애 | 지역별로 PG가 1개씩이면 **지역마다 단일 장애점**이 생긴다. 중간 계층을 빼도 장애 조치(failover)가 생기지 않는다. 이 구성의 "멀티"는 이중화가 아니라 **지역 분할**이다 |
| 수수료 | 해외 결제 수수료, 페이팔 환전 스프레드, 환불 때 돌려받지 못하는 고정 수수료 [추정] |
| 잠김(lock-in) | 원문 내재화(`payment_events.raw_payload`)는 실제 이득이다. 다만 정기결제 **빌링키는 PG에 묶여** 옮겨지지 않는다 [추정] |

### B. 두 번째 PG가 기존 설계를 깨는 자리 ← ADR-016이 기록하라고 한 것

| # | 깨지는 것 | 근거 |
|---|---|---|
| B1 | **페이팔은 KRW를 지원하지 않는다.** `CoinProduct`는 KRW 3종뿐이고, `findByPrice()`로 통화 안에서 금액을 역검색한다 → 통화별 가격표가 필요하고 환율 위험을 떠안는다 | [확인-문서] 지원 통화 24종에 KRW 없음 · [확인-코드] [`CoinProduct.php:37`](../src/Payment/CoinProduct.php) |
| B2 | **PG의 소수 자릿수가 ISO 4217과 다르다.** 페이팔은 HUF·JPY·TWD를 소수 0자리로 받는데, ISO에서 HUF·TWD는 2자리다. `MinorUnits`(ISO 기준)를 그대로 쓰면 PG가 거절한다 | [확인-문서] · [확인-코드] [`MinorUnits.php`](../src/Channel/MinorUnits.php) |
| B3 | **이니시스 카드 결제에는 서버 간 웹훅이 없다.** 결과는 브라우저가 `returnUrl`로 POST하고, 우리 서버가 `authUrl`로 승인을 요청하는 **동기 흐름**이다. "PG별 웹훅 엔진"이라는 과제 틀이 국내 PG에서는 성립하지 않는다 | [확인-문서] 웹표준 매뉴얼 STEP2~4. 노티는 가상계좌용만 따로 있다 |
| B4 | **승인은 났는데 우리 DB가 실패하면 망취소**를 해야 한다(인증 결과 후 10분 이내). 승인 요청이 타임아웃돼 성공 여부를 모를 때도 같다. 망취소를 빠뜨리면 **돈은 빠졌는데 코인이 없다**. 망취소를 일반 취소 용도로 써서는 안 된다 | [확인-문서] |
| B5 | **서명 방식이 PG마다 다르다.** 스텁은 HMAC, 페이팔은 인증서 기반 RSA-SHA256(`전송ID\|시각\|웹훅ID\|crc32`), 이니시스는 요청 쪽 SHA256(`signature`·`verification`)이다. 지금의 `WebhookSignature` 하나로는 받을 수 없다 | [확인-문서] |
| B6 | 페이팔의 **postback 검증 API는 이벤트 JSON을 다시 인코딩해 보내야** 한다. 저장소 규칙 ①("원본 바이트로 서명")과 충돌한다 → **자체 검증(원본 바이트 crc32 + 인증서)**을 택한다 | [확인-코드] [`WebhookSignature.php:20`](../src/Payment/WebhookSignature.php) |
| B7 | `payments.pg`가 `'stub'`으로 **하드코딩**돼 있다. PG 거래 ID(tid·order·capture)를 담을 칸이 없다. 페이팔 환불 웹훅은 우리 uid가 아니라 capture ID를 가리킬 수 있다 | [확인-코드] [`Payment_model.php:101`](../application/models/Payment_model.php) · [추정] |
| B8 | **재전송할 때 서명을 새로 만드는가** — [plan-payment-webhook.md](plan-payment-webhook.md) 11장에 "모른다"로 남은 항목이다. 재전송이 처음 시각을 그대로 쓰면 300초 창이 **재전송을 전부 거절**한다. 페이팔은 최대 3일 동안 25회 재전송한다 | [확인-문서] 재전송 정책 · 서명 갱신 여부는 실측 |
| B9 | **이니시스는 구매자 이름·휴대폰·이메일이 필수다**(`buyername*` `buyertel*` `buyeremail*`). 가입이 없어 받을 곳이 없다. 승인 결과로도 되돌아온다(`buyerName` `buyerTel` `buyerEmail`) → C5 허용 목록에서 뺀다 | [확인-원문] 0단계 ([worklog 09-19](worklog.md)) |
| B11 | **웹 요청 안에서 외부 HTTP 를 부르게 된다.** 매체 전송은 "웹 요청 중엔 외부 HTTP 를 부르지 않는다 — 그게 아웃박스를 둔 이유" 였다. PG 승인은 사용자가 복귀 페이지에서 기다리는 동안 동기로 해야 코인을 줄 수 있어 아웃박스로 미룰 수 없다. 승인 타임아웃(10초)이 곧 복귀 페이지의 최악 응답 시간이다 | [확인-코드] 구현 중 (`CurlHttpClient` 주석을 고쳤다) |
| B12 | **`payment_events.source` 가 ENUM(`api`·`webhook`·`admin`)이었다.** 복귀 승인을 `return` 으로 적으면 strict 모드에서 오류 → 롤백 → **모든 이니시스 결제가 망취소**된다. 단위 테스트는 DB 를 안 타서 못 잡는다 | [확인-코드] 구현 뒤 스키마 대조에서 발견 → 마이그레이션 `20260919000200` |
| B10 | **PC 웹표준과 모바일은 다른 규격이다**(`P_` 파라미터, SHA512 금액 해시, 망취소 "인증TID 기준 10분, 승인TID 기준 1분"). 이번 범위는 PC뿐이라 **모바일 브라우저에서는 결제할 수 없다** | [확인-원문] 0단계 |

### C. 보안·프라이버시

| # | 리스크 | 근거 |
|---|---|---|
| C1 | **`authUrl`·`netCancelUrl`은 브라우저 POST에 실려 온다 = 공격자가 조작할 수 있다.** 검증하지 않으면 가짜 승인 서버가 `0000`을 돌려줘 **코인이 무료로 지급**되고 SSRF 경로도 열린다. `idc_name`(fc·ks·stg) ↔ 허용 호스트 대조가 필수다 | [확인-문서] "IDC센터코드와 비교 검증 필수" |
| C2 | **CSRF 403.** `csrf_protection=TRUE`라서 이니시스가 보내는 교차 사이트 POST는 제외 목록에 넣지 않으면 막힌다. `webhooks/pg` 때 이미 한 번 겪었다 | [확인-코드] [`config.php:131-142`](../application/config/config.php) |
| C3 | **SameSite=Lax 쿠키가 결과 복귀 요청에 실리지 않는다**(세션, `ab_vid`). 복귀 처리는 쿠키가 아니라 `orderNumber`(=payment_uid)로 결제를 찾아야 한다. `Visitor::current()`를 부르면 `landing_path=/pay/inicis/return`인 유령 방문이 생겨 **어트리뷰션 분모를 오염**시킨다 → [domains-and-cookies.md](domains-and-cookies.md) | [확인-코드] `config.php:99`, [`Purchase.php:50`](../application/controllers/Purchase.php) |
| C4 | **인증 없는 `/purchase`의 전제가 무너진다.** [결정 5](plan-payment-webhook.md)의 근거는 "스텁이라 청구가 없다"였다. 실PG가 붙으면 **공개 페이지에서 누구나 실결제**를 할 수 있다 | [확인-코드] `Purchase.php:26`, `config.php` 주석 |
| C5 | **`raw_payload`에 PG 응답 원문**을 넣으면 카드 정보(부분 마스킹)와 페이팔 결제자 개인정보가 **5년 동안 보존**된다(PCI·개인정보 범위) | [추정] 필드 목록은 0단계에서 확인 |
| C6 | 페이팔 판매자 보호는 **디지털 재화를 제외**한다. 코인을 쓴 뒤 분쟁을 걸면 손실은 우리가 진다. 지금 상태 머신에는 `disputed`·`reversed`가 없다 | [확인-검색] 공식 원문은 가져오지 못함 |
| C7 | 페이팔 이용 정책(AUP)의 성인 콘텐츠 제한 — 성인 콘텐츠를 파는 서비스라면 **계정 제한·자금 동결** 위험이 있다 | [미확인] AUP 원문 가져오기 실패 |
| C9 | **위조 복귀 요청으로 남의 결제를 실패시킬 수 있다.** 브라우저 입력(authUrl · mid · 인증 실패 코드)만 보고 거절한 것을 `failed` 로 적으면, payment_uid 를 아는 사람이 위조 POST 하나로 정상 결제를 막는다 → 결과 타입을 `failed`(PG 가 거절 · 장부 반영)와 `rejected`(우리가 요청 전에 거절 · **장부 불변**)로 나눴다 | [확인-코드] 구현 중 발견 · `GatewayResult` |
| C8 | **개인정보 처리방침이 "실제 회원가입과 결제(청구)는 없습니다"** 라고 적고 있다. 테스트 MID 는 실승인이다. 방침 스스로 "실제 가입·결제 기능이 생기면 이 표를 먼저 고칩니다" 라고 약속했으므로 **실카드 결제 전에 방침부터** 고친다 | [확인-코드] `application/views/privacy/index.php:54·109` |

### D. 운영·정산

| # | 리스크 | 근거 |
|---|---|---|
| D1 | **이니시스 테스트 MID는 실제 카드로 출금**되고, 매일 23:00~23:50에 **자동 취소**된다. PG가 **우리에게 알리지 않고** 취소하므로, 우리 DB에는 `captured`·코인·GA4 매출이 남는다. 이것이 **PG 쪽 대사**가 없을 때의 모양이다([`LedgerReconciliation`](../src/Payment/LedgerReconciliation.php)은 내부 장부끼리만 맞춘다) | [확인-문서] 이니시스 FAQ · [확인-코드] |
| D2 | 테스트 결제가 **운영 GA4 매출에 섞인다** — [worklog 09-18](worklog.md) "확인 작업이 운영 지표를 틀었다"와 같은 유형이다 | [확인-코드] |
| D3 | 재전송 한도(3일)를 넘긴 웹훅은 사라진다 → 오래된 `created`·`pending`을 PG 조회 API로 쓸어 담는 배치가 필요하다 | [확인-문서] |
| D4 | 결제 수단마다 환불 방법이 다르다(휴대폰 결제는 당월에만, 가상계좌는 환불 계좌가 필요하다) | [추정] → 이번 범위는 **카드만** |
| D5 | 테스트 키가 운영에 들어가거나 그 반대인 경우 — 결제는 성공하는데 돈이 들어오지 않는다 | 설계 → `check-env.sh`에서 모드 일치 검사 |
| D6 | PG에서 돌아온 브라우저 세션이 GA4에서 `paypal.com / referral`로 **새로 시작**된다 — 도메인·리다이렉트를 건너며 데이터가 끊기는 문제 그대로다 | [추정] → GA4 "원치 않는 추천" 설정 |

---

## 2. 구현 설계

### 핵심 판단: 하나로 모으는 자리는 "웹훅 엔진"이 아니라 `applyEvent()`

동기 결과(이니시스 승인, 페이팔 capture)와 비동기 웹훅(페이팔, 스텁)이 **모두 같은 CAS 전이**(`Payment_model::applyEvent`)로 들어가게 한다. 중복·역전 방어는 이미 측정을 마쳤으므로([D-3](failure-scenarios.md)) 새로 만들지 않는다.
**페이팔은 동기 capture 뒤에 `COMPLETED` 웹훅이 한 번 더 온다** → **자연 발생한 중복 수신**이다. D-3을 실PG에서 다시 관측할 수 있다.

### 새로 만들 파일

| 파일 | 역할 |
|---|---|
| `src/Payment/Gateway/GatewayInterface.php` | `name()` · `supports(currency)` · `start(payment)` → 브라우저가 쓸 값 · `confirm(input)` → `GatewayResult` · `refund(refs, amount)` |
| `src/Payment/Gateway/GatewayResult.php` | 목적 상태(`captured`/`pending`/`failed`) 또는 **`unknown`** · PG 참조 ID · 저장용 필드 · 오류 분류(재시도 가능/종결) |
| `src/Payment/Gateway/InicisGateway.php` | 서명 필드 생성(`oid·price·timestamp` SHA256, `mKey`) · 복귀 값 검증 · **`idc_name`→호스트 허용 목록**(C1) · 승인 · 망취소 |
| `src/Payment/Gateway/PaypalGateway.php` | OAuth → 주문 생성(`custom_id`=payment_uid, `PayPal-Request-Id`=멱등키) · capture · 환불 |
| `src/Payment/Gateway/PaypalWebhook.php` | **자체 검증**: 원본 바이트 crc32 + 인증서(`paypal-cert-url` 호스트 허용 목록 + 캐시) · 이벤트 타입 → 상태 매핑 · 분쟁·역전 이벤트는 기록만(6장 C6) · **시각 창 없음**(6장 B8) |
| `src/Payment/Tax/{TaxPolicy,TaxQuote,PassThroughTaxPolicy}.php` | **만들었다(09-19).** 세무는 범위 밖이라 인터페이스와 통과 구현만 둔다 — 금액 불변, `reason=out-of-scope`(6장 A) |
| `src/Payment/Gateway/PgAmount.php` | PG별 소수 자릿수(페이팔 HUF·JPY·TWD=0). `MinorUnits`(ISO)와 **일부러 따로 둔다**(B2) |
| `src/Payment/Gateway/PayloadRedactor.php` | 저장할 필드만 남기는 허용 목록(C5). 원문 전체 대신 `sha256(raw)`를 함께 남긴다 |
| `application/libraries/Gateways.php` | [`Channels.php`](../application/libraries/Channels.php)와 같은 조립 방식. 통화 → PG 규칙을 한 곳에(KRW→inicis, USD→paypal) |
| `application/controllers/Pay.php` | **입장권**(6장 C4 — 09-19 오후에 "토큰 잠금" 에서 "버튼으로 누구나" 로 바뀌었다) · `GET /pay`(시연 결제 화면, noindex) · `POST /pay/inicis/start` · `POST /pay/inicis/return` · `/pay/inicis/close` · `POST /pay/paypal/order` · `POST /pay/paypal/capture` · `GET /pay/result/{uid}` |
| `application/views/pay/{index,result}.php` | 바닐라 JS([ADR-009](decisions/ADR-009-no-spa.md)). INIStdPay.js / 페이팔 JS SDK |
| `application/migrations/20260919000100_create_payment_pg_refs.php` | `payment_pg_refs(payment_id, pg, ref_type, ref_value, created_at)`, `UNIQUE(pg, ref_type, ref_value)` (B7) |
| `tests/Payment/Gateway/*Test.php` | 4장 검증 1 |
| `docs/decisions/ADR-020-multi-pg-direct.md` | 시나리오의 사유 · 1장의 리스크 · **깨진 것 B1~B8** · 기각한 대안(통합 PG) · ADR-016과 양방향 링크 |

### 고칠 기존 파일

- `Payment_model.php` — `createIfAbsent()`가 `pg`를 받는다(하드코딩과 `→ ADR-008` 주석 교체) · `applyEvent(..., $source='webhook')`(`return`·`capture`·`admin` 추가) · `addPgRef()`·`findByPgRef()`
- `Purchase.php` — 선택 필드 `pg`(기본 `stub` → D-3 측정 스크립트 유지), `gateway->supports(currency)` 검사, **`pg≠stub` 이면 토큰 필수**, 결정 5 주석 갱신(C4)
- `Webhook.php` — `paypal()` 추가. 기존 `pg()`(스텁 HMAC)는 그대로 둔다
- `CoinProduct.php` — **USD 2종**($9.99·$24.99) 추가(6장 A 수수료). 코인 수는 국내 비율 × 가정 환율이고, 그 가정을 주석에 적는다. 통화 안에서 금액이 유일하다는 불변식은 기존 테스트가 지킨다
- `cli/Verify.php` — `payments` 에 **오래된 `created`·`pending`** 항목 추가(6장 D1·D3)
- `src/Channel/HttpClient.php` + `CurlHttpClient` + 테스트용 가짜 구현 — `postForm()`에 `headers` 추가(페이팔 OAuth Basic 인증). **인터페이스 변경이라 구현체를 전부 grep으로 찾는다**
- `config/routes.php` · `config.php`의 `csrf_exclude_uris`에 `pay/inicis/return`·`pay/inicis/close` 추가(C2) · `.env.example`(키 이름만) · `scripts/check-env.sh`(PG 모드 일치 검사, D5)
- `cli/Pg.php` — `refund <uid>`(PG 환불 API → `applyEvent(refunded, source=admin)`) · `list`. **웹 어드민은 만들지 않는다** — 인증이 없으면 누구나 누를 수 있는 환불 버튼이 된다. ADR-020에 그 이유를 적는다

### 이 결정을 참조하는 곳까지 따라간다

`grep -rn "ADR-016\|ADR-008\|PgAGateway\|스텁이다\|스텁 PG 라\|청구도 없다\|마이그레이션뿐" docs application src README.md` 결과를 전부 갱신한다. 알고 있는 곳:

- [ADR-016](decisions/ADR-016-payment-and-notification.md) 결과의 "실제 정산·환불이 일어나지 않는다" → **틀렸다**(D1). ADR-020으로 링크
- [ADR-017](decisions/ADR-017-ci3-application-structure.md) 44행 디렉터리 목록의 `PgAGateway · PgBGateway`
- [architecture.md](architecture.md) 201행의 "`payments` 는 마이그레이션뿐이다"(이미 낡음)
- [plan-payment-webhook.md](plan-payment-webhook.md) 0장("범위에서 뺀 것: GatewayInterface")과 11장의 "재전송 서명" 미확인 항목 → 실측 결과로 닫는다
- [decisions/README.md](decisions/README.md) 표 · README "아직 없는 것" · [api-spec.md](api-spec.md)(새 엔드포인트)
- **개인정보 처리방침** `application/views/privacy/index.php` — "결제(청구)는 없습니다" · 위탁 표에 PG 추가(C8). **실카드 결제보다 먼저**

---

## 3. 순서와 컷 라인

| 단계 | 내용 | 게이트 |
|---|---|---|
| **0** (≈1h) | 규격 원문 확보 → 5장 출처의 축자 발췌를 [worklog](worklog.md)에 남긴다(ADR-016의 "착수 전 확인"). [추정] 항목 검증: INIAPI 환불 키가 테스트 MID에 공개돼 있는가, 복귀가 iframe 안에서 열리는가(`X-Frame-Options: SAMEORIGIN`), 승인 응답 필드 목록, 페이팔 환불 리소스에 `custom_id`가 있는가 · 사람이 할 일(아래) | 원문 없이 코드를 쓰지 않는다 |
| **1** 이니시스 | 인터페이스 · `InicisGateway` + 테스트 · 마이그레이션 · `Pay` 복귀 흐름 · 망취소 → 배포 → 실결제 1건 → `cli/pg refund` | 09-20 정오까지 운영에서 `captured`→`refunded` 관통 |
| **2** 페이팔 | 주문·capture · 자체 서명 검증 웹훅 · 환불 · 재전송 서명 실측 | 09-20 밤 |
| **3** 문서 | ADR-020 · worklog · 참조 갱신 · README | Phase 1 마감 전 |

**컷 순서**(밀리면 이 순서로 버린다): ① 재전송 서명 실측 → ② 페이팔 웹훅(동기 capture만 유지) → ③ 페이팔 전체 → **이니시스 + 스텁으로 후퇴**(ADR-016에 적힌 후퇴선). 이니시스까지 밀리면 **리스크 문서(ADR-020)만 내고 코드는 Phase 2**로 넘긴다. 어떤 경우에도 README에는 **운영에서 확인한 것만** 적는다.

### 사람이 해야 할 것 (0단계, 코드 밖)

1. 페이팔 개발자 계정 → 샌드박스 REST 앱(client id/secret) → 웹훅 등록(`https://app.<도메인>/webhooks/paypal`, 이벤트 `PAYMENT.CAPTURE.*`) → **webhook id** → 서버 `.env`에만 넣는다
2. **이니시스 테스트 결제는 본인 카드에서 실제로 출금된다**(당일 23시대 자동 취소). 동의가 먼저 필요하다. 그날 안에 `cli/pg refund`로 우리가 먼저 취소한다
3. GA4 관리 화면의 "원치 않는 추천"에 `inicis.com`·`paypal.com` 추가(D6)

---

## 4. 검증

1. **단위 테스트**(도커 `php:8.2-cli` 안에서 `vendor/bin/phpunit`, CI도 동일)
   - 이니시스 대조 벡터 셋을 고정값으로 쓴다. **출처가 서로 다르다** — [worklog 09-19](worklog.md) 막힌 것 1
     - signature: `sha256("oid=INIpayTest_1361252896871&price=1004&timestamp=1361252896871")` = `422a0e78529b419d9412d6e344c6e138584d9174c691da6cd91d4330240b9192` — **이니시스 공개 해시 도구의 출력**. 매뉴얼 원문의 예시 해시(`ec1e9c63…`)는 이 평문과 맞지 않는다(문서 오류)
     - `mKey` = sha256(테스트 MID signKey) = `3a9503069192f207491d4b19bd743fc249a761ed94246c8c42fed06c3cd15a33` — **매뉴얼 원문과 일치**
     - INIAPI 환불 `hashData`(SHA512, 매뉴얼의 카드취소 예시) = `b2dc4d43…5f92d6d69` — **매뉴얼 원문과 일치**
   - 서명 평문의 필드 이름 대소문자(`signKey`)는 로그인 뒤 샘플에만 있다 → 단위 테스트가 아니라 **스테이징이 판정**한다
   - `idc_name=fc` + `authUrl=https://evil.example` → 거절되고 **HTTP 호출 0회**(가짜 `HttpClient`로 확인)
   - 승인 성공 + `applyEvent` 실패(`db-error`) → `netCancelUrl`로 망취소 1회 / 승인 타임아웃 → 망취소
   - 페이팔 웹훅: 샌드박스에서 실제로 받은 원본 바이트와 헤더를 고정값으로 → 통과, 1바이트 변조 → 거절, 인증서 URL 호스트 위조 → 거절
   - `PgAmount`: HUF는 페이팔 0자리, ISO 2자리 — **둘이 다르다는 것을 테스트로 못박는다**
   - `PayloadRedactor`: 카드번호·결제자 이메일 필드가 남지 않음
2. **운영 관통**: ~~이니시스 1건 → `payments.captured` · `coin_lots` 1 · `conversions` 1 · 아웃박스 → GA4. 환불 → `refunded` · 코인 회수 · GA4 `refund`~~ → **실카드 검증을 하지 않기로 했다(6장).** 이니시스는 무과금 확인 둘(결제창 열기 · 없는 tid 환불)만 하고, 승인 뒤 경로는 단위 테스트(가짜 `HttpClient`)로만 확인한다. 페이팔은 샌드박스로 관통한다. `cli/verify payments` 불일치 0
3. **페이팔 자연 중복**: capture(동기) 뒤 `COMPLETED` 웹훅 → `payment_events`의 전이(`from<>to`) 1행 + 무시(`from=to`) ≥1행, `coin_lots` 1행
4. **재전송 서명**: 첫 배달에 503을 주는 스위치(대조군 관례) → 재배달의 `transmission-time`이 새 값인지 기록 → plan-payment-webhook.md 11장을 닫는다
5. **복귀 경로**: 이니시스 복귀 뒤 `visits`의 `MAX(id)`가 늘지 않는다(C3), CSRF 403 없음(C2)
6. **참조 갱신**: 2장의 `grep`을 다시 돌려 남은 참조 0
7. 결과는 측정 원문 그대로 worklog → [failure-scenarios.md](failure-scenarios.md) · [benchmarks.md](benchmarks.md)로 옮긴다. **절차를 먼저 커밋하고 그다음에 측정한다**

---

## 5. 출처 (09-19 조회)

| 무엇 | 출처 | 상태 |
|---|---|---|
| 페이팔 지원 통화 24종(KRW 없음), 소수 0자리 HUF·JPY·TWD | [PayPal Currency Codes](https://developer.paypal.com/reference/currency-codes/) | 확인 |
| 페이팔 웹훅 서명(헤더 4종, `transmissionId\|timeStamp\|webhookId\|crc32`, SHA256withRSA), 재전송 3일간 25회 | [PayPal Webhooks](https://developer.paypal.com/api/rest/webhooks/rest/) | 확인. 순서 보장은 문서에 언급 없음 |
| 이니시스 웹표준 STEP2~4, `authUrl`·`idc_name` 대조, 망취소 10분 | [KG이니시스 웹표준 연동가이드](https://manual.inicis.com/pay/stdpay_pc.html) | 확인 |
| 이니시스 테스트모드 실출금 + 자동 취소 | 원문 "결제테스트 시 지불수단별로 거래가 실승인 됩니다" · "당일 자정 이전에 자동취소 됩니다. (매입전송X)" — [std-info (2022-07 아카이브)](http://web.archive.org/web/20220702225403/https://manual.inicis.com/stdpay/std-info.php) · FAQ 는 23:00~23:50 | **확인-원문** (0단계) |
| 이니시스 승인 호스트 | 스테이징 `stgstdpay` · 운영 `fcstdpay`·`ksstdpay` · `idc_name` [fc, ks, stg] — [PC 일반결제](https://manual.inicis.com/pay/stdpay_pc.html) | **확인-원문** (0단계) |
| 테스트 MID `INIpayTest` 와 signKey · INIAPI 키 | 이니시스가 예전에 공개한 원문 — [std-info (2022-07)](http://web.archive.org/web/20220702225403/https://manual.inicis.com/stdpay/std-info.php) · [iniapi/api-info (2021-06)](http://web.archive.org/web/20210619023141/https://manual.inicis.com/iniapi/api-info.php). 현행 매뉴얼에서는 **가맹점 로그인 뒤**라 받지 않았다 | 확인-원문. **지금도 유효한지는 스테이징에서** |
| 이니시스 환불(INIAPI) | [취소/환불](https://manual.inicis.com/pay/cancel.html) — `/api/v1/refund`, SHA512 `hashData`, 성공 "00" | **확인-원문** (0단계) |
| 페이팔 판매자 보호에서 디지털 재화 제외 | [Chargeflow 요약](https://www.chargeflow.io/blog/what-is-paypal-seller-protection) · [공식 페이지](https://www.paypal.com/us/legalhub/paypal/seller-protection) | 공식 원문은 가져오지 못함(잘림) |
| 페이팔 AUP 성인 콘텐츠 | [공식 페이지](https://www.paypal.com/us/legalhub/paypal/acceptableuse-full) | **미확인**(잘림) |
| 페이팔 한국 가맹점 요율: 해외 4.40% + $0.30, 환전 3%, 차지백 $10, 분쟁 $8, **환불해도 원래 수수료는 반환되지 않음** | [PayPal KR 가맹점 수수료](https://www.paypal.com/kr/webapps/mpp/merchant-fees) | 확인 |
| 분쟁·역전 웹훅 `CUSTOMER.DISPUTE.CREATED` · `PAYMENT.CAPTURE.REVERSED` | [PayPal Disputes webhooks](https://developer.paypal.com/docs/disputes/webhooks/) | 확인(검색 요약) |
| 카드번호는 앞 6·뒤 4자리를 넘지 않게 자르면 카드 소지자 데이터가 아님 | [PCI SSC Data Storage Do's and Don'ts](https://listings.pcisecuritystandards.org/pdfs/pci_fs_data_storage.pdf) | 확인(검색 요약). 이니시스 `CARD_Num` 형식은 미확인 |
| 재전송 때 서명을 새로 만드는가 | 페이팔 문서에 언급 없음 | **미확인** → 6장 B8 에서 위험 자체를 없앴다 |

---

## 6. 결정 — 2026-09-19

리스크마다 대응할지 받아들일지를 정했다. **받아들인 것은 무엇을 감수하는지를 함께 적는다.**

| 리스크 | 결정 | 하는 것 / 감수하는 것 |
|---|---|---|
| A 세무 | **인터페이스만, 통과** | `TaxPolicy` + `PassThroughTaxPolicy`. 금액을 바꾸지 않고 `reason=out-of-scope` 를 남긴다 — "세금 0" 이 계산 결과가 아니라 미룬 판단임을 나중에 골라낼 수 있게. **아직 어디서도 부르지 않는다** |
| A 장애 | **수용** | 지역마다 단일 장애점이다. 대신 타임아웃·결과 불명은 망취소(B4)로 처리해 장애가 "결제 불가"로 끝나게 한다. **멀티는 지역 분할이지 이중화가 아니다** |
| A 수수료 | **해외는 큰 묶음만** | USD 2종 $9.99·$24.99. 해외 요율(4.40% + $0.30)로 계산하면 $2.99 는 14.4%, **$9.99 는 7.4%, $24.99 는 5.6%**. 국내와 가격 구조가 달라지는 것을 감수한다 |
| A 잠김 | **해당 없음** | 범위가 코인 구매라 정기결제 빌링키가 생기지 않는다 |
| B1·B2 | **USD 한 통화 고정가** | B2 는 USD 에서 드러나지 않는다. `PgAmount` 와 "다른 통화 거절" 테스트로 막아 둔다. 비유럽 구매자는 자기 카드사 환전 수수료를 낸다 |
| B3·B4·B5·B7 | 대응 (선택지 없음) | 수용하면 결제가 성립하지 않거나 위조가 가능해진다 |
| B6 | **자체 검증** | 원본 바이트 규칙을 지킨다. 인증서 URL 호스트 허용 목록과 캐시를 직접 만든다 |
| B8 | **시각 창 없이 CAS 에 맡김** | 진짜 서명의 재생은 같은 전이를 다시 시도하는 것일 뿐이라 무시된다(D-3). 종결 상태는 되돌릴 수 없다. **스텁(HMAC, 300초 창)과 규칙이 달라진다.** 재전송 서명 실측은 컷 ① 로 남는다 |
| C1·C2·C3 | 대응 (선택지 없음) | |
| C4 | ~~토큰 잠금~~ → **뒤집음(09-19 오후): 공개 + 입장권 버튼** | 처음 결정은 공유 토큰을 아는 사람만(`/pay/*` · `/purchase` pg≠stub). 면접관이 별도 링크 없이 해 볼 수 있게 **안내 화면(`/tour#pay`)의 버튼으로 누구나 입장권**(서명 · 만료 1일)을 받게 바꿨다 → 아래 「C4 를 뒤집은 것」 |
| C5 | **허용 목록 + 원문 sha256** | 카드번호·결제자 개인정보가 보존 범위에 들어오지 않는다. **허용 목록 밖 필드는 나중에 분쟁이 생겨도 복원할 수 없다** |
| C6 | **기록만, 판단은 사람** | 분쟁·역전 이벤트를 `from=to` 로 남기고 error 로그. 상태와 코인은 바꾸지 않는다([RefundPolicy](../src/Payment/RefundPolicy.php) ② 와 같은 판단). **손실을 받아들인다** |
| C7 | 해당 없음 | 이 프로젝트에는 성인 콘텐츠가 없다. AUP 원문은 확인하지 못했다 |
| D1·D3 | **오래된 결제 보고 + 당일 환불** | `cli/verify payments` 가 오래된 `created`·`pending` 을 보고한다. 테스트 결제는 그날 `cli/pg refund`. ~~INIAPI 환불 키를 얻지 못하면 → 수동 기록~~ → **0단계에서 테스트 MID 의 INIAPI 키를 공개 원문으로 확인해 INIAPI 환불로 간다**(키가 무효면 그때 수동 기록으로 후퇴). PG 조회 API·정산 파일 대사는 하지 않는다 |
| D2 | **표시 없이 보냄** (검토한 추천과 다름) | 테스트 결제도 운영 GA4 에 실거래와 구분 없이 간다 — 스텁 결제도 지금 그렇다. 당일 환불로 **순매출은 맞지만, GA4 에서 테스트와 실거래를 가려낼 수 없다.** `/metrics` 와 반영률 표본에도 섞인다 |
| D4 | **카드만** | 수단별 분기는 `GatewayResult` 에 결제 수단 칸만 둔다 |
| D5 | 대응 | `check-env.sh` 에서 PG 모드 일치 검사 |
| D6 | 설정 | GA4 "원치 않는 추천"에 PG 도메인 추가(사람 작업) |
| 통합 어드민 | **CLI 만** | `cli/pg list·refund`. 인증 없는 환불 버튼은 누구나 누를 수 있는 버튼이 된다. 보여 줄 화면은 없다 |

### 0단계(이니시스) 뒤에 추가로 정한 것 — 2026-09-19

| 리스크 | 결정 | 하는 것 / 감수하는 것 |
|---|---|---|
| 실카드 검증 | **하지 않는다** (검토한 추천과 다름) | 대신 **돈이 빠지지 않는 확인 둘**: ① 스테이징 결제창을 열고 카드 입력 전에 닫는다 — 서명·`mKey`·`signKey` 대소문자를 결제창이 받아 주는가 [추정: 결제창이 이 시점에 검사한다] ② INIAPI 에 **없는 tid** 로 환불 1회 — "해시 오류"와 "거래 없음"을 갈라 공개 키가 아직 유효한지 본다. **승인·망취소·환불 성공 경로는 운영에서 확인되지 않은 채 남는다** — README 에 "운영 미확인"으로 적는다 |
| B9 구매자 정보 | **시연용 고정값** | 서버 `.env` 의 이름·번호·이메일만 보낸다. 실제 개인정보는 서버를 지나지 않고 DB 에도 남지 않는다. 이메일은 우리가 받을 수 있는 주소로 둔다 |
| B10 모바일 | **PC 결제창으로 시도** (검토한 추천과 다름) | 모바일 규격을 구현하지 않고 안내도 하지 않는다. 모바일에서는 동작하지 않거나 이상하게 동작할 수 있고, **그 실패를 사용자가 먼저 발견한다** → **09-19 오후 뒤집음**: 모바일은 안내 + 카드 없는 길(아래 「모바일 — B10 을 뒤집었다」) |
| C8 방침 | 대응 — **실PG 경로를 배포하기 전에** | 실카드 검증을 안 해도 토큰을 가진 사람은 실승인을 일으킬 수 있다 |

### 1단계(이니시스 구현) 중에 정한 것 — 2026-09-19

선택지가 없는 것들이라 묻지 않고 정했다. 근거는 [worklog 09-19](worklog.md).

| 무엇 | 결정 |
|---|---|
| C9 위조 복귀 | 결과를 `failed`(PG 가 거절 → 장부 반영)와 `rejected`(요청 전 우리가 거절 → **장부 불변**, 로그만)로 나눈다 |
| B11 웹 요청 안의 외부 HTTP | 받아들인다. 승인 타임아웃 10초(`INICIS_TIMEOUT_MS`), 넘기면 결과 불명 → 망취소 |
| B12 source ENUM | `return` 을 **끝에** 더하는 마이그레이션(메타데이터 변경만) |
| 되돌릴 수 없는 승인 | 망취소 URL 이 허용 목록 밖이면 **승인 자체를 요청하지 않는다** |
| 동시 복귀 | 이 요청의 승인이 장부에 오르지 않았으면(다른 요청이 먼저 captured) **이 요청이 망취소**한다 — 한 결제 두 청구를 막는다 |
| 망취소 호스트 | 승인과 같은 센터라는 원문이 없어 **모드 안의 호스트 전체**를 허용한다 [추정] |
| 닫기 페이지 | 결제창(다른 오리진) 안 프레임에서 열리면 엣지의 `X-Frame-Options: SAMEORIGIN` 에 막힐 수 있다 [추정] → **09-19 확인: 닫기 URL 이 불리고(200) 결제창은 닫힌다.** 우리 페이지가 프레임 안에서 그려졌는지는 가려내지 못했다 — 보기에 문제는 없어 두었다 → **09-19 오후**: 닫으면 결과 화면으로 간다(닫기 페이지가 상위 화면을 옮긴다 — 우리 화면이 부모라는 [추정]이 맞았다) |
| README | **배포와 무과금 확인 뒤에** 고친다. 운영에서 확인한 것만 적는다 → 09-19 고침 |

### 운영 확인 결과 — 2026-09-19 ([worklog](worklog.md))

| 확인 | 결과 |
|---|---|
| 무과금 ① 결제창 | **열린다** — 우리가 서명한 필드로 스테이징 결제창이 "코인 30개 · 3,300원" 카드 선택까지. 닫기 → "결제를 취소하시겠습니까?" → 확인 → 닫힘. 카드 입력 없음 |
| 무과금 ② INIAPI 키 | **유효** — 없는 tid 로 환불: `01 해당거래 없음`. 대조군(틀린 키): `ERR205 hashData 불일치`. 해시 형식·KST 시각을 받아들였다 |
| C1 위조 승인 주소 | 거절(HTTP 0회), 결제는 `created` 그대로 — C9 도 함께 확인 |
| C4 토큰 | 없으면 404, `pg=inicis` 는 쿠키 없이 403, 스텁 경로는 그대로 201 |
| **확인하지 못한 것** | 승인 요청(STEP3)의 서명 · 망취소 · 환불 **성공** — 실카드 없이는 닿을 수 없다(사용자 결정) |

### C4 를 뒤집은 것 — 2026-09-19 오후

**결정**: 안내 탭에서 결제를 해 볼 수 있게, 공개로 열되 **입장권 받기 버튼**을 둔다(사용자 결정). 검토했던 추천은 "토큰 유지 + 안내에서 연결"이었다.

| | 처음(토큰 잠금) | 지금(입장권 버튼) |
|---|---|---|
| 누가 여는가 | 공유 비밀을 아는 사람 | **버튼을 누른 누구나** |
| 쿠키 | 비밀의 해시 — 모두 같은 값 | 방문자마다 다른 **서명된 입장권**, 만료 1일 (`PayAccess::issue`) |
| 입장권 없이 `/pay` | 404 (문을 숨긴다) | 받기 안내 화면 (문이 공개됐으니 숨길 이유가 없다) |
| 막는 것 | 사람 | 크롤러(버튼은 POST)와 "모르고 누르기"(버튼 옆에 실승인 문장) |
| 운영자 지름길 | `/pay?t=<비밀>` | 그대로 |

**받아들이는 것** — 처음 C4 가 막으려던 것이 그대로 열린다:

- 모르는 사람의 카드가 **실제로 승인된다**(자정 전 자동 취소). 버튼과 결제창 사이에 두 번 알린다(안내 문장 · 이니시스 결제창의 약관 동의)
- 그 결제가 운영 GA4 에 **구분 없이** 들어간다 — D2 의 결정("표시 없이 보냄")이 공개와 만나 커진다. 당일 자동 취소는 **우리에게 알리지 않으므로**(D1) GA4 의 매출은 상쇄되지 않는다. 우리가 `cli/pg refund` 한 것만 상쇄된다
- 누구나 결제 행을 쌓을 수 있다. 한도는 두지 않았다(검토한 선택지 「공개하되 한도」를 고르지 않았다)

### 카드 없는 길 — 2026-09-19 오후

면접관은 데모를 눌러 **되는지만** 빠르게 본다는 판단에서 시작했다. 대부분 모르는 포트폴리오에 실카드를 넣지 않으므로, 이니시스 경로로는 "결제창이 뜬다" 까지만 보이고 결과(장부 · 코인 · 매체)에는 닿지 않는다. 충돌 테스트(중복 8개 등)는 공개 버튼이 아니라 **면접 시연**으로 둔다.

| 무엇 | 결정 (사용자) |
|---|---|
| 방식 | **가짜 PG 한 바퀴 버튼** — `/pay` 맨 위. `/purchase`(스텁, `demo-` 키) → `POST /pay/stub/confirm` → `/pay/result/{uid}` |
| 웹훅 경로 | **자기 `/webhooks/pg` 로 실제 HTTP** — HMAC 검증 · 웹훅 컨트롤러 · 응답 코드까지 운영 경로 그대로. 웹 요청 안의 외부 HTTP 는 B11 과 같은 예외이고, 자기 호출이 php-fpm 작업자를 하나 더 잡는다(`pm.max_children = 8`) |
| 범위 | **확정 + 같은 웹훅 한 번 더** — 결과 화면에 무시 줄이 남아 "같은 알림이 두 번 와도 한 번" 이 면접 전에 보인다. 결과 문구는 고정 문장이 아니라 **장부에서 센 값**(확정 전이 수 · 무시 수 · 코인 지급 건)을 그대로 보인다 |
| 매체 | **그대로 보낸다**(검토한 추천은 "보내지 않는다") |
| 남용 방지 | 확정 엔드포인트는 입장권 + **그 버튼이 방금 만든 결제만**: 스텁 · `created` · `demo-` 키 · 10분 이내 (`StubWebhook::demoRejects`). 기존 결제·측정용 결제는 건드리지 못한다 |

**받아들이는 것**: "서명이 GA4 오염을 막는 실질 방어선"([plan-payment-webhook 결정 5](plan-payment-webhook.md))에 **서버가 대신 서명하는 문이 하나 생겼다.** 누구나 버튼 한 번으로 운영 GA4·Meta 에 3,300원짜리 구매 전환을 쌓을 수 있다. 그 전제를 적어 둔 코드·문서(Purchase · config · WebhookSignature · plan-payment-webhook · failure-scenarios)에 이 예외를 링크했다.

### 모바일 — B10 을 뒤집었다 (2026-09-19 오후)

**실측** iPhone 흉내로 이니시스 PC 결제 버튼을 누르면 결제창이 열리지 않고 경고만 뜬다:
`[INIStdPay / Dev. Error] 해당기기로는 정상적인 결제가 진행되지 않을 수 있습니다. PC로 결제 진행을 부탁드립니다.`
같은 날 누군가의 실기기(iOS 18.7)도 이 버튼을 눌렀다.

**결정(사용자)** 처음 결정 "모바일도 PC 결제창으로 시도 · 안내 없음"을 뒤집는다 — 모바일에서는 이니시스 버튼과 스크립트를 그리지 않고 "카드 결제창은 PC 에서" 를 보인다. **카드 없는 길은 모바일에서도 된다.** 면접관이 폰으로 열었을 때 첫 화면이 "Dev. Error" 가 되지 않게 하는 것이 목적이다.

**판별은 엣지 한 곳** — `docker/openresty/nginx.conf` 의 `map $is_mobile` 을 `fastcgi_param AB_IS_MOBILE` 로 앱에 넘긴다. 앱에 같은 정규식을 또 두지 않는다. 확인하다가 `.env.example` 의 `MOBILE_REDIRECT_ENABLED` 를 **아무도 읽지 않는다**는 것을 찾았다 — `lp.` 의 모바일 302 는 늘 켜져 있다. 이번엔 적어 두기만 했다.
