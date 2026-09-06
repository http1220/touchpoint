# ADR-006 · 코인을 잔액 컬럼이 아니라 원장으로

**상태**: 확정 (2026-09-01)

## 배경

전환의 실체는 결제다. 결제하면 코인이 지급되고, 코인으로 회차를 본다. 가장 단순한 설계는 이것이다.

```sql
users.coin_balance INT
```

## 문제

플랫폼 A **이용약관**에 이렇게 적혀 있다.

| 재화 | 유효기간 |
|---|---|
| **유료 코인** | **5년** |
| **무료·이벤트 코인** | **1년** |

→ [조사 요약](../research-method.md) 6-4

**만료가 다른 재화를 한 숫자로 합치면 어느 코인이 언제 만료되는지 알 수 없다.** `coin_balance = 150`에서 유료가 몇 개고 무료가 몇 개인지, 각각 언제 사라지는지 복원할 방법이 없다. 이 설계로는 **구현 자체가 불가능하다.**

> 이 규칙은 UI를 아무리 봐도 안 나온다. **약관에만 있다.** 리서치에서 약관을 읽은 이유가 이것이다.

## 결정

**지급 건별 원장(lot)** 으로 관리한다.

```sql
coin_lots (
  id, user_id,
  kind       ENUM('paid','free'),    -- 만료 규칙이 갈리는 축
  source     VARCHAR(32),            -- purchase|event|attendance
  channel    ENUM('web','ios','android'),
  amount, remaining,
  payment_id BIGINT NULL,            -- 환불 시 역추적
  granted_at, expires_at,            -- paid=+5y, free=+1y
  KEY ix_spend_order (user_id, remaining, expires_at, kind)
)
coin_spends (id, user_id, lot_id, amount, episode_id, spent_at)
```

**소진 순서**: 만료 임박 순 → 무료 우선.

## 근거

사용자에게 유리하고 분쟁이 적다. 유료 코인을 먼저 태우면 "돈 주고 산 게 먼저 사라졌다"는 항의가 나온다. 순서 규칙 자체를 문서화하는 것이 설계의 일부다.

`channel`을 두는 이유: 플랫폼 A 위탁사 명단에 **Google·Apple**이 있다 = 앱 인앱결제(IAP)를 웹 결제와 함께 운영한다. **IAP는 환불을 스토어가 직접 처리**하므로 같은 코인이라도 취득 채널에 따라 환불 경로가 다르다.

## 기각한 대안

| 대안 | 기각 사유 |
|---|---|
| `users.coin_balance INT` | 만료 규칙 구현 불가 |
| 잔액 컬럼 + 별도 만료 테이블 | 두 소스가 어긋날 수 있다. 원장이 단일 진실 |
| 이벤트 소싱 전면 도입 | 이 규모에 과함. `coin_lots` + `coin_spends`로 충분 |
| 만료를 배치로 일괄 차감 | 배치 실패 시 잔액이 틀어진다. **조회 시점에 `expires_at`으로 판정**하는 게 안전 |

## 결과

- **감수**: 잔액 조회가 `SUM(remaining) WHERE expires_at > NOW()` 집계가 된다. 인덱스로 대응
- **얻음**: 환불 시 어느 lot을 회수할지 `payment_id`로 역추적 가능
- **파생 규칙**: 약관의 "이용 내역이 없을 때만 청약철회" → **환불 가부가 `coin_spends` 존재 여부에 종속**된다. 결제 도메인이 콘텐츠 열람 기록에 의존하는 지점이며, 서비스를 쪼갤 때 가장 먼저 터진다
