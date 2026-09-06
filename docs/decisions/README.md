# 아키텍처 결정 기록 (ADR)

각 결정의 **배경 → 결정 → 근거 → 기각한 대안 → 결과(트레이드오프)** 를 한 파일에 담는다.
"왜 안 썼냐"에 답할 수 있는 것이 "무엇을 썼냐"보다 중요하다.

| # | 결정 | 한 줄 |
|---|---|---|
| [001](ADR-001-php-codeigniter.md) | PHP 8.3 + CodeIgniter 4 | 공고 스택. 세션 쿠키 `ci_session`으로 실측 확인 |
| [002](ADR-002-two-registered-domains.md) | **등록 도메인 2개** | 서브도메인 3개로는 same-site라 서드파티 쿠키 실험이 성립하지 않음 |
| [003](ADR-003-mysql-outbox.md) | MySQL 아웃박스 | 전환과 전송 지시를 **한 트랜잭션**에. Redis·SQS는 정합성 구멍 |
| [004](ADR-004-skip-locked.md) | `FOR UPDATE SKIP LOCKED` | 중복 전송을 사후 차단이 아니라 **DB가 예방** |
| [005](ADR-005-channel-adapter.md) | 채널 어댑터 | 매체 **10종 이상** 실측. 신규 매체 = 클래스 1개 + 설정 1줄 |
| [006](ADR-006-coin-ledger.md) | 코인 원장 | 약관의 **유료 5년 / 무료 1년**은 잔액 컬럼으로 구현 불가 |
| [007](ADR-007-read-write-split.md) | 읽기/쓰기 분리 | `…slave=sdb3` 쿠키 실측 → 복제 지연 재현 |
| [008](ADR-008-stub-pg.md) | 스텁 PG | PG 30개사. 어댑터는 이미 005에서 증명 — 여기선 상태 머신만 |
| [009](ADR-009-no-spa.md) | SPA 미사용 | 백엔드 포지션 + 대상 서비스가 서버 렌더 + 추적 스니펫은 바닐라여야 |
| [010](ADR-010-no-kubernetes.md) | K8s·ECR 미사용 | 컨테이너 4개. EKS 잡이 없는 것도 관측됨 |
| [011](ADR-011-github-actions-over-jenkins.md) | GitHub Actions | **초안의 "CI 공백" 전제가 틀렸음.** 조직 표준은 Jenkins |

---

## 리서치가 뒤집은 결정 3개

초안을 그대로 갔으면 틀렸을 항목들이다.

| ADR | 초안 | 실제 |
|---|---|---|
| **002** | 서브도메인 3개면 충분 | same-site라 **실험 자체가 성립 안 함** |
| **004** | `dedup_key`로 사후 차단 | 전송 중복은 못 막음 → `SKIP LOCKED`로 예방 |
| **011** | "Jenkins 조직이라 CI 공백" | **공백이 아니라 조직 표준.** 명분을 교체 |

관측 근거는 전부 [조사 요약](../research-method.md)에 있다.
