# 아키텍처

> 이 프로젝트는 **웹툰 플랫폼이 아니다.** 광고 유입부터 결제 전환까지를 추적해 매체로 되돌려 보내는 **어트리뷰션 파이프라인**이며, 도메인(회원·코인·회차)은 전환을 측정할 대상이 필요해서 최소한만 두었다.
> 도메인 설계 근거는 전부 [조사 요약](research-method.md)의 공개 자료 조사에서 나왔다.

---

## 1. 한 장 요약

```mermaid
flowchart LR
    AD["광고 매체<br/>(Google/Meta/...)"] -->|"클릭<br/>utm·gclid·fbclid"| BR

    subgraph SHOP["toonlab.example — 광고주 측 (first-party)"]
        BR["lp. /go<br/>브리지 302"]
        LP["lp. /l/{work}<br/>랜딩"]
        APP["app. /signup /purchase<br/>서비스·전환"]
        MET["app. /metrics<br/>지표"]
    end

    subgraph TRACK["abridge.example — 추적 측 (third-party)"]
        API["api. /collect /conversion<br/>수집 API"]
    end

    BR --> LP
    LP -->|"cross-site XHR<br/>CORS + SameSite=None"| API
    LP --> APP
    APP -->|"전환 등록"| API
    API --> DB[("MySQL 8.0")]
    DB --> W["워커<br/>아웃박스 폴링"]
    W --> GA["GA4 채널<br/>Measurement Protocol"]
    W --> META["Meta 채널<br/>Conversions API"]
    DB --> MET
```

**핵심**: 광고주 도메인과 추적 도메인을 **서로 다른 등록 도메인**으로 갈랐다. 이 결정 하나가 이 프로젝트의 전부다 → [ADR-002](decisions/ADR-002-two-registered-domains.md)

---

## 2. 컨테이너 구성

```mermaid
flowchart TB
    subgraph EC2["AWS EC2 t3.micro + swap 2GB"]
        CADDY["caddy<br/>자동 HTTPS · 4개 호스트 · 리버스 프록시"]
        PHP["app (php-fpm 8.3)<br/>CodeIgniter 4"]
        WORKER["worker<br/>php spark dispatch:work"]
        MYSQL[("mysql 8.0")]
        CADDY --> PHP
        PHP --> MYSQL
        WORKER --> MYSQL
    end
    WORKER -->|HTTPS| EXT["GA4 / Meta"]
```

| 컨테이너 | 역할 | 비고 |
|---|---|---|
| `caddy` | TLS 자동 발급(ACME), 4개 호스트네임 라우팅, 302 리다이렉트 | `SameSite=None`이 HTTPS를 요구하므로 필수 |
| `app` | CodeIgniter 4 / PHP 8.3-fpm | 서버 렌더. SPA 없음 → [ADR-009](decisions/ADR-009-no-spa.md) |
| `worker` | 아웃박스 폴링 → 매체 전송 | `app`과 **같은 이미지**, 커맨드만 다름 |
| `mysql` | MySQL 8.0 | `FOR UPDATE SKIP LOCKED` 사용 → [ADR-004](decisions/ADR-004-skip-locked.md) |

> 컨테이너 4개다. Kubernetes를 쓰지 않는 이유 → [ADR-010](decisions/ADR-010-no-kubernetes.md)

---

## 3. 데이터 흐름 — 클릭에서 매체 전송까지

```mermaid
sequenceDiagram
    autonumber
    participant U as 브라우저
    participant LP as lp.toonlab (광고주)
    participant API as api.abridge (추적)
    participant APP as app.toonlab (서비스)
    participant DB as MySQL
    participant W as 워커
    participant M as GA4 / Meta

    U->>LP: GET /go?pid=..&subpid=..&utm_*&gclid
    LP->>DB: visits + touchpoints(first) 저장
    LP-->>U: 302 → /l/{work} + Set-Cookie ab_vid
    U->>LP: GET /l/{work} (랜딩)
    U->>API: POST /collect  (cross-site, preflight 발생)
    Note over U,API: SameSite=None; Secure; Partitioned
    API->>DB: touchpoints(last) 갱신
    U->>APP: POST /signup
    APP->>DB: users + signup_touchpoint 스냅샷
    APP->>DB: conversions(signup) + dispatch_outbox × 채널수
    U->>APP: POST /purchase (코인 결제)
    APP->>DB: payments(captured) + coin_lots + conversions(purchase)
    loop 폴링
        W->>DB: SELECT ... FOR UPDATE SKIP LOCKED
        W->>M: 전송
        W->>DB: dispatch_log (parse/db/send 구간별)
    end
```

### 각 단계에서 벌어지는 일

| # | 단계 | 기술적 쟁점 |
|---|---|---|
| 1~3 | **브리지 리다이렉트** | 302 vs 301 캐시 차이. 파라미터 전달 방식 2가지 비교 → [api-spec](api-spec.md#2-get-lp-go--브리지-리다이렉트) |
| 4~5 | **랜딩 + 크로스사이트 수집** | preflight, `Allow-Credentials`, `Vary: Origin`, `sendBeacon` 트레이드오프 |
| 6~7 | **가입 전환** | 유입 경로를 `users`에 **스냅샷으로 복사** — `touchpoints`는 3개월 후 파기되므로 |
| 8 | **결제 전환** | `captured` 시점에만 발화. 코인은 원장(lot)에 적립 |
| 9~11 | **아웃박스 워커** | `SKIP LOCKED`로 다중 워커 안전, 지수 백오프, 구간별 계측 |

---

## 4. 두 도메인이 만드는 세 가지 상황

| 호출 | 관계 | 무슨 일이 벌어지나 |
|---|---|---|
| `lp.toonlab` → `api.abridge` | **cross-site + cross-origin** | preflight + `SameSite=None; Secure` + 브라우저별 차단 |
| `lp.toonlab` → `app.toonlab` | same-site, cross-origin | CORS는 필요, 쿠키는 `Lax`로 전송 |
| `app.toonlab` 내부 | same-origin | 아무 제약 없음 |

> **대조군이 있어야 실험이 성립한다.** 서브도메인만 나누면 첫 행이 사라지고, 그러면 이 프로젝트의 존재 이유가 없어진다 → [domains-and-cookies](domains-and-cookies.md)

---

## 5. 읽기/쓰기 커넥션 분리

```mermaid
flowchart LR
    W1["쓰기 경로<br/>수집·전환·결제"] --> PRIMARY[("primary")]
    R1["읽기 경로<br/>/metrics 지표"] --> REPLICA[("replica<br/>(지연 주입 가능)")]
    PRIMARY -.->|"복제 지연"| REPLICA
```

플랫폼 A에서 `…slave=sdb3` 쿠키를 관측했다 — **읽기 복제본에 세션을 고정**하는 구조다([조사 요약](research-method.md) 3-2).

이 프로젝트는 복제본을 실제로 띄우지는 않되 **커넥션 그룹을 분리**하고, 지연을 인위적으로 주입해 **"방금 기록한 전환이 지표 화면에 안 보인다"** 를 재현한다 → [ADR-007](decisions/ADR-007-read-write-split.md), [failure-scenarios](failure-scenarios.md)

---

## 6. 채널 어댑터

```mermaid
classDiagram
    class ChannelInterface {
        <<interface>>
        +name() string
        +buildPayload(Conversion) array
        +send(array) DispatchResult
    }
    ChannelInterface <|.. Ga4Channel
    ChannelInterface <|.. MetaChannel
    ChannelInterface <|.. NoopChannel
    class DispatchWorker {
        +run()
    }
    DispatchWorker --> ChannelInterface : 채널 목록을 주입받음
```

플랫폼 A에 붙어 있는 매체가 **10종 이상**이다(태그 관리 컨테이너 3개, 검색·디스플레이 전환 ID 10개 이상, 소셜 픽셀 2, DSP 2, 글로벌 소셜 2). 매체별 `if` 분기로는 유지가 불가능하다 → [ADR-005](decisions/ADR-005-channel-adapter.md)

**신규 매체 추가 비용 = 어댑터 클래스 1개 + 설정 1줄.** 이걸 README에서 수치로 보여준다.

---

## 7. 환경

| 환경 | 도메인 | 용도 |
|---|---|---|
| 로컬 | `*.localhost` | 개발. 크로스사이트 실험은 불가(same-site) |
| 운영 | `toonlab.example` / `abridge.example` | **실험은 여기서만 성립** |

> `.example`은 문서용 placeholder다. 실제 구매 도메인으로 치환한다 (`.env`의 `SHOP_DOMAIN` / `TRACK_DOMAIN`).

---

## 8. 의도적으로 하지 않은 것

| 안 한 것 | 이유 |
|---|---|
| 다중 AZ·읽기 복제본 실물 | 단일 t3.micro. **Well-Architected 신뢰성 기둥을 의도적으로 포기** |
| Kubernetes / ECR | 컨테이너 4개 → [ADR-010](decisions/ADR-010-no-kubernetes.md) |
| Redis / SQS | → [ADR-003](decisions/ADR-003-mysql-outbox.md) |
| SPA / React | → [ADR-009](decisions/ADR-009-no-spa.md) |
| 실제 PG 연동 | 결제는 스텁. 상태 머신·멱등성만 진짜로 구현 |
| 웹툰 뷰어·랭킹·검색 | 광고 연동과 무관 → [조사 요약](research-method.md) 버린 것 24개 |
