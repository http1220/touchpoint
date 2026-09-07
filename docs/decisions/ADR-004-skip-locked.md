# ADR-004 · 워커 동시성을 `FOR UPDATE SKIP LOCKED`로

**상태**: 확정 (2026-09-07) — 초안 대비 **변경**

## 배경

아웃박스 워커를 여러 개 띄우면 같은 행을 두 워커가 집어 **중복 전송**이 발생한다.

초안은 이걸 `conversions.dedup_key UNIQUE`로 **사후 차단**하려 했다. 즉 "중복이 발생하긴 하는데 결과적으로 막힌다"는 접근이다.

## 문제

`dedup_key`는 **전환 생성**의 중복은 막지만 **전송**의 중복은 막지 못한다. 이미 아웃박스에 적재된 행을 두 워커가 동시에 읽으면 매체로 **두 번 전송된다.** 매체 쪽 전환 수치가 부풀려진다.

또한 MySQL 기본 격리수준인 **REPEATABLE READ**에서는 갭 락이 걸려, 락을 그냥 기다리게 하면 워커들이 서로를 막아 수평 확장이 안 된다.

## 결정

폴링 쿼리에 **`FOR UPDATE SKIP LOCKED`** 를 쓴다. MySQL 8.0에서 지원한다.

```sql
SELECT * FROM dispatch_outbox
WHERE status = 'pending' AND next_retry_at <= NOW(3)
ORDER BY id
LIMIT 100
FOR UPDATE SKIP LOCKED;
```

**이중 방어 구조**:

| 계층 | 막는 것 |
|---|---|
| `conversions.dedup_key UNIQUE` | 중복 **전환 생성** |
| `dispatch_outbox UNIQUE (conversion_id, channel)` | 중복 **적재** |
| **`FOR UPDATE SKIP LOCKED`** | 중복 **처리(전송)** |

## 근거

락 대기 대신 **잠긴 행을 건너뛰게** 하면 워커가 서로 막지 않고 각자 다른 배치를 가져간다. 워커를 4개로 늘려도 처리량이 선형에 가깝게 증가한다.

**사후 차단이 아니라 DB가 예방한다.** 예방이 항상 낫다 — 사후 차단은 "이미 잘못된 요청이 나간 뒤"를 전제하기 때문이다.

이 결정은 인덱스 실습과도 맞물린다. `(status, next_retry_at, id)` 복합 인덱스가 있어야 `SKIP LOCKED`가 스캔하는 행 수도 줄어든다 → [data-model](../data-model.md#폴링-쿼리--인덱스-실습의-본체)

## 기각한 대안

| 대안 | 기각 사유 |
|---|---|
| `dedup_key`만으로 사후 차단 (초안) | 전송 중복을 못 막는다 |
| 워커 1개만 실행 | 수평 확장 불가. 워커가 죽으면 전체 정지 |
| `SELECT ... FOR UPDATE` (SKIP 없이) | 워커끼리 락 대기. 확장이 안 됨 |
| 애플리케이션 레벨 분산 락(Redis) | 브로커를 안 쓰기로 한 [ADR-003](ADR-003-mysql-outbox.md)과 모순 |
| `status`를 먼저 UPDATE 후 SELECT | 라운드트립 2회 + 워커 죽으면 행이 영구 잠김 |

## 결과

- **제약**: MySQL 8.0 이상 필수 (MariaDB라면 10.6+). **MySQL 8.0으로 확정됨**
- **검증**: 워커 컨테이너를 2개 띄우고 `dispatch_log`에 중복 전송이 **0건**인지 확인 → [failure-scenarios](../failure-scenarios.md)
- **시연 가치**: 워커 1개 vs 4개 처리량 비교를 `benchmarks.md`에 수치로 남긴다
