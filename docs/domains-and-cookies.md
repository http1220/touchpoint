# 도메인과 쿠키

> **이 문서가 프로젝트의 척추다.** 여기 적힌 도메인 구성이 없으면 나머지 실험이 전부 성립하지 않는다.

---

## 1. 왜 등록 도메인이 2개여야 하는가

브라우저의 same-site 판정 기준은 **오리진이 아니라 등록 도메인(eTLD+1)** 이다. 서브도메인만 나누면 이렇게 된다.

| 구성 | CORS preflight | `SameSite=None` 필요 | 서드파티 쿠키 차단 재현 |
|---|---|---|---|
| `lp.` / `api.` / `app.example.com` (서브도메인 3개) | 발생 | **불필요** — `Lax`로 전송됨 | **불가능** |
| `lp.sshwan.com` → `api.khan-edge.com` (**등록 도메인 2개**) | 발생 | **필요** | **가능** |

> 서브도메인 3개는 cross-**origin**이지만 same-**site**다. 광고 어트리뷰션의 핵심 난제(서드파티 쿠키)를 하나도 겪을 수 없다.
> 상세 근거 → [ADR-002](decisions/ADR-002-two-registered-domains.md)

---

## 2. 도메인 배치

```
── 광고주 측 (first-party) ──────────────
  lp.sshwan.com      랜딩 · 브리지 리다이렉트
  app.sshwan.com     서비스 · 가입 · 결제 · 지표

── 추적 사업자 측 (third-party) ──────────
  api.khan-edge.com     수집 API · 전환 등록
```

| 호출 | 관계 | 역할 |
|---|---|---|
| `lp.sshwan.com` → `api.khan-edge.com` | **cross-site** | **실험군** — 여기서 모든 문제가 발생한다 |
| `lp.sshwan.com` → `app.sshwan.com` | same-site, cross-origin | **대조군** — 같은 CORS인데 쿠키는 통과한다 |

> **대조군이 있다는 게 이 설계의 값어치다.** "cross-site면 막히고 same-site면 통과한다"를 나란히 보여줘야 원인이 오리진이 아니라 사이트라는 게 증명된다.

### DNS

두 도메인의 A 레코드를 **같은 EIP**로 향하게 한다. Caddy 한 대가 호스트네임 3개를 받고 ACME HTTP-01로 각각 인증서를 발급한다.

```
lp.sshwan.com    A  <EIP>
app.sshwan.com   A  <EIP>
api.khan-edge.com   A  <EIP>
```

> ACME는 **staging 엔드포인트로 먼저 검증**한다. 설정 시행착오로 Let's Encrypt rate limit에 걸리면 일주일을 날린다.

---

## 3. 쿠키 정책표

| 쿠키 | 발급 도메인 | `Domain` | `SameSite` | `Secure` | `HttpOnly` | `Partitioned` | 수명 | 담는 값 |
|---|---|---|---|---|---|---|---|---|
| `ab_vid` | `lp.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ✅ | — | 1년 | **visit_uid만** (UUIDv7) |
| `ab_sid` | `app.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ✅ | — | 세션 | 로그인 세션 |
| `ab_tid` | `api.khan-edge.com` | `.khan-edge.com` | **`None`** | ✅ | ✅ | ✅ | 1년 | **추적 ID (서드파티)** |
| `ab_g4cid` | `app.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ❌ | — | 2년 | GA4 client_id 복제 |

### 설계 결정 4가지

**1. 쿠키에는 `visit_uid`만 담는다.**
어트리뷰션 본체(utm, gclid, pid…)는 서버 DB에 둔다. 클라이언트가 값을 바꿔도 데이터가 오염되지 않는다. 플랫폼 A도 자체 방문자 ID 쿠키만 쿠키에 두고 나머지는 서버에서 처리한다.

**2. `ab_tid`만 `SameSite=None; Secure; Partitioned`.**
크로스사이트에서 전송되어야 하는 유일한 쿠키다. `Partitioned`(CHIPS)는 `SameSite=None; Secure`와 **반드시 함께** 써야 효력이 있다.

**3. `ab_g4cid`는 `HttpOnly`가 아니다.**
GA4 클라이언트 스크립트가 읽고 써야 하고, 서버도 읽어야 한다. Measurement Protocol 전송에 `client_id`가 필요하기 때문이다. 플랫폼 A 글로벌의 `g4_client_id`와 같은 목적이다.

**4. 만료는 명시적으로 짧게.**
`visits`는 3개월 후 파기된다([data-model](data-model.md#7-보존정책)). 쿠키가 1년을 살아도 서버에 대응 레코드가 없으면 새 방문으로 처리된다 — 이 불일치를 문서화해 둔다.

---

## 4. 어트리뷰션 파라미터 체계

플랫폼 A 실측 쿠키에서 확인된 구조를 그대로 따른다.

```
…pid_join = pidko=defaultPid & subpidko=defaultSubPid & channelko=defaultChannel
```

| 파라미터 | 의미 | 예시 |
|---|---|---|
| `pid` | **매체·파트너 식별자** | `google`, `meta`, `criteo` |
| `subpid` | **캠페인·소재 단위 하위 식별자** | `2609_romance_a` |
| `channel` | **유입 채널 구분** | `search`, `display`, `social` |

여기에 업계 표준 파라미터를 함께 받는다.

| 표준 | 처리 |
|---|---|
| `utm_source` `utm_medium` `utm_campaign` `utm_content` `utm_term` | 그대로 저장 |
| `gclid` | Google 클릭 ID. UTM과 **별개로** 받는다 |
| `fbclid` | Meta 클릭 ID |

> **first / last 두 벌로 보존한다.** 플랫폼 A가 `pid_join`(가입시점)과 `pid_last`(최종)를 나눠 두는 것과 같다. 가입 전환에는 first-touch가, 재방문 구매에는 last-touch가 필요하다.

---

## 5. 브라우저별 예상 동작 — 실험 매트릭스

`failure-scenarios.md`에서 이 표를 **실측으로 채운다.** 지금은 가설이다.

| 브라우저 | 설정 | `ab_vid` (first-party) | `ab_tid` (third-party) | 예상 |
|---|---|---|---|---|
| Chrome | 기본 | 통과 | ? | 서드파티 쿠키 유지(2025 철회) → 통과 예상 |
| Chrome | 서드파티 차단 ON | 통과 | ? | 차단 예상 |
| Safari | 기본 (ITP) | 통과 | ? | **차단 예상** |
| Firefox | 기본 (TCP) | 통과 | ? | **차단 예상** |
| Chrome | `Partitioned` 적용 시 | 통과 | ? | 파티션 단위로 통과 예상 |

> **중요**: Chrome은 2025년에 서드파티 쿠키 폐지를 **철회**했고 Privacy Sandbox도 종료했다. 그래서 이 실험의 주제는 *"쿠키가 사라진다"* 가 아니라 ***"브라우저마다 다르다"*** 다. 상세 → [조사 요약](research-method.md)

---

## 6. 서버사이드 전송이 필요한 이유 (재정의)

| 흔한 설명 | 이 프로젝트의 설명 |
|---|---|
| "서드파티 쿠키가 사라지니까" | **틀림.** Chrome은 유지하기로 했다 |
| — | **Safari·Firefox는 여전히 차단한다.** 크로스 브라우저로 보면 어트리뷰션이 샌다 |
| — | 광고 차단기가 클라이언트 픽셀을 막는다 |
| — | 페이지 이탈 중 전송 실패는 **재시도가 불가능**하다. 서버 큐는 재시도된다 |

> 이 재정의가 면접 카드 1번이다 → [조사 요약](research-method.md)

---

## 7. 검증 방법

| 항목 | 방법 |
|---|---|
| 쿠키 속성 | DevTools → Application → Cookies에서 `Domain`·`SameSite`·`Secure`·`Partitioned` 육안 확인 |
| cross-site 판정 | `lp.sshwan.com` → `api.khan-edge.com` 요청에 `ab_tid`가 붙는지 |
| same-site 대조 | `lp.sshwan.com` → `app.sshwan.com` 요청에 `ab_vid`가 붙는지 |
| preflight | Network 탭에 **OPTIONS 요청이 실제로 뜨는지** |
| 차단 재현 | 브라우저별 설정을 바꿔가며 5장 매트릭스를 채운다 |
