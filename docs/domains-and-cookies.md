# 도메인과 쿠키

> **이 문서가 프로젝트의 척추다.** 도메인 배치가 어떤 실험을 가능하게 하고 어떤 실험을 막는지가 여기서 정해진다.

---

## 1. 사이트와 오리진은 다른 축이다

이 프로젝트가 등록 도메인 1개로 가면서, **무엇이 남고 무엇이 빠지는지**를 먼저 못박는다 → [ADR-018](decisions/ADR-018-single-registered-domain.md)

브라우저는 두 가지 경계를 따로 본다.

| | 기준 | 무엇을 좌우하는가 |
|---|---|---|
| **오리진** | scheme + host + port | **CORS** — preflight, `Allow-Origin`, credentials |
| **사이트** | 등록 도메인(**eTLD+1**) | **쿠키** — 서드파티 차단, `SameSite` |

`lp.sshwan.com` → `api.sshwan.com` 은 **사이트는 같고 오리진은 다르다.**

| | 이 구성 | 등록 도메인 2개였다면 |
|---|---|---|
| CORS preflight | **발생** | 발생 |
| `Vary: Origin` 필요 | **필요** | 필요 |
| `Allow-Origin: *` + credentials 충돌 | **재현됨** | 재현됨 |
| `SameSite=None` 필요 | 불필요 (`Lax`로 전송) | 필요 |
| 서드파티 쿠키 차단 | **재현 불가** | 재현 가능 |

> **"크로스사이트가 아니면 CORS도 없다"는 흔한 오해다.** CORS는 오리진 기준이라 서브도메인만 달라도 그대로 걸린다. 처음에 나도 둘을 묶어서 **잃는 실패 시나리오를 4개로 과대평가했다. 실제로는 2개다**(A-1·A-2).

---
## 2. 도메인 배치

```
── 광고주 측 (first-party) ──────────────
  lp.sshwan.com      랜딩 · 브리지 리다이렉트
  app.sshwan.com     서비스 · 가입 · 결제 · 지표

── 추적 사업자 측 (third-party) ──────────
  api.sshwan.com     수집 API · 전환 등록
```

| 호출 | 관계 | 역할 |
|---|---|---|
| `lp.sshwan.com` → `api.sshwan.com` | same-site, **cross-origin** | **실험군** — CORS 가 여기서 발생한다 |
| `lp.sshwan.com` → `app.sshwan.com` | same-site, cross-origin | **대조군** — 같은 구조. 수집과 서비스를 가른 것은 역할 분리다 |

> **수집을 `api.` 로 가른 것은 역할 분리다.** 같은 사이트라 쿠키는 세 호스트에 다 붙지만, 오리진이 달라 CORS 는 걸린다 — 그래서 `/collect` 는 preflight 를 띄우고 `/l/{work}` 는 띄우지 않는다. **그 대비가 이 배치의 값어치다.**

### DNS

두 도메인의 A 레코드를 **같은 EIP**로 향하게 한다. Caddy 한 대가 호스트네임 3개를 받고 ACME HTTP-01로 각각 인증서를 발급한다.

```
lp.sshwan.com    A  <EIP>
app.sshwan.com   A  <EIP>
api.sshwan.com   A  <EIP>
```

> ACME는 **staging 엔드포인트로 먼저 검증**한다. 설정 시행착오로 Let's Encrypt rate limit에 걸리면 일주일을 날린다.

---

## 3. 쿠키 정책표

| 쿠키 | 발급 도메인 | `Domain` | `SameSite` | `Secure` | `HttpOnly` | `Partitioned` | 수명 | 담는 값 |
|---|---|---|---|---|---|---|---|---|
| `ab_vid` | `lp.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ✅ | — | 1년 | **visit_uid만** (UUIDv7) |
| `ab_sid` | `app.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ✅ | — | 세션 | 로그인 세션 |
| `ab_tid` | `api.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ✅ | — | 1년 | 수집 측 식별자 |
| `ab_g4cid` | `app.sshwan.com` | `.sshwan.com` | `Lax` | ✅ | ❌ | — | 2년 | GA4 client_id 복제 |

### 설계 결정 4가지

**1. 쿠키에는 `visit_uid`만 담는다.**
어트리뷰션 본체(utm, gclid, pid…)는 서버 DB에 둔다. 클라이언트가 값을 바꿔도 데이터가 오염되지 않는다. 플랫폼 A도 자체 방문자 ID 쿠키만 쿠키에 두고 나머지는 서버에서 처리한다.

**2. `ab_tid` 도 `SameSite=Lax` 다.**
등록 도메인이 하나라 `api.` 로 가는 요청도 same-site 다. `None` 을 쓸 이유가 없고, 쓰면 오히려 필요 없는 노출을 만든다.

> 원래 계획은 이 쿠키를 `SameSite=None; Secure; Partitioned` 로 두고 브라우저별 차단을 재는 것이었다. `Partitioned`(CHIPS)는 `None; Secure` 와 **반드시 함께** 써야 효력이 있다 — 이 사실은 조사로 확인했지만 **이 구성에서는 검증할 수 없다** → [ADR-018](decisions/ADR-018-single-registered-domain.md)

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

## 5. 브라우저별 동작 — 하지 않는다

원래 이 장에는 Chrome·Safari·Firefox의 서드파티 쿠키 차단 매트릭스가 들어갈 예정이었다. **등록 도메인 1개에서는 성립하지 않는다** → [ADR-018](decisions/ADR-018-single-registered-domain.md) · [failure-scenarios A장](failure-scenarios.md)

조사로 확인한 사실만 남긴다. 이건 도메인과 무관하게 유효하다.

| 사실 | 출처 |
|---|---|
| Chrome은 서드파티 쿠키 폐지를 **철회**했다 (2024-07, 2025-04) | [조사 요약](research-method.md) |
| Privacy Sandbox는 2025-10-17 종료 | 〃 |
| Safari(ITP)·Firefox(TCP)는 **계속 차단** | 〃 |

> **그래서 이 문제는 "사라지는 문제"가 아니라 "브라우저 편차 문제"다.** 이 재정의가 조사의 산출물이고, 실측을 못 한다고 해서 바뀌지 않는다. 다만 **실측하지 않은 것을 실측한 것처럼 쓰지 않는다.**

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
| 쿠키 속성 | DevTools → Application → Cookies 에서 `Domain`·`SameSite`·`Secure` 육안 확인 |
| **cross-origin 판정** | `lp.sshwan.com` → `api.sshwan.com` 요청이 **OPTIONS preflight 를 띄우는지** |
| **same-origin 대조** | 같은 요청을 `lp.` 자기 자신에게 보내면 preflight 가 **뜨지 않는지** |
| `Vary: Origin` | 응답 헤더에 붙는지. 없으면 캐시가 오리진을 섞는다 |
| credentials 충돌 | `Allow-Origin: *` 로 바꾸면 `credentials: 'include'` 요청이 **실패하는지** |
| 쿠키 전송 | 세 호스트 모두 same-site 이므로 `Lax` 로 전송된다 — **이게 정상이고, 차단 실험은 하지 않는다** |
