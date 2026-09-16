#!/bin/sh
# DB 통합 테스트 — 동시성 · 멱등성 · 대사.
#
#   sh tests/integration/run.sh      (저장소 루트에서, DB 가 최신 마이그레이션 상태)
#
# 운영 서버에서 손으로 재던 것(failure-scenarios D-1 · D-3)을 CI 가 매번 잰다.
# 막는 것이 DB 의 조건부 UPDATE · UNIQUE · SKIP LOCKED 라서 DB 없이는 테스트가 안 된다.
# 사고 조사와의 대응 → docs/incidents/testable-cases.md
set -eu
cd "$(dirname "$0")/../.."
. tests/integration/lib.sh

N=8

# 1~6 은 매체 채널 없이 — 결제·환불 전환이 noop 아웃박스에 섞이면 7 의 건수가 흐려진다
export CHANNELS=
USER_UID=$(st user)

echo "── 1. 같은 멱등 키로 결제 생성 ${N}개 동시 (Visa 재시도 중복)"
KEY="it-idem-$(date +%s)"
parallel $N php public/index.php cli/selftest payment "$USER_UID" "$KEY" > /dev/null
expect "결제 행" 1 "$(st count payments_by_key "$KEY")"

echo "── 2. 같은 captured 웹훅 ${N}개 동시 (조건부 UPDATE)"
PAY=$(st payment "$USER_UID" "it-cas-$(date +%s)" | sed 's/.*"uid":"\([0-9a-f]*\)".*/\1/')
parallel $N php public/index.php cli/selftest apply "$PAY" captured > /dev/null
expect "captured 전이" 1 "$(st count captured_transitions "$PAY")"
expect "코인 lot"      1 "$(st count lots "$PAY")"
expect "구매 전환"     1 "$(st count conversions "$PAY" purchase)"

echo "── 3. 대조군 — 먼저 조회해서 판정하면 코인이 여러 번 나간다"
# 대조군이 조용히 안전해지면 2번이 무엇을 증명하는지 흐려진다. 그래서 '깨지는 것' 을 확인한다.
CTRL=$(st payment "$USER_UID" "it-ctrl-$(date +%s)" | sed 's/.*"uid":"\([0-9a-f]*\)".*/\1/')
PAYMENT_WEBHOOK_PRECHECK=true PAYMENT_WEBHOOK_PRECHECK_DELAY_MS=400 \
	parallel $N php public/index.php cli/selftest apply "$CTRL" captured > /dev/null
expect_ge "대조군 코인 lot" 2 "$(st count lots "$CTRL")"
expect    "대조군 구매 전환은 여전히 1 (전환 UNIQUE)" 1 "$(st count conversions "$CTRL" purchase)"

echo "── 4. 같은 환불 웹훅 ${N}개 동시 (토스·Santander 두 번 실행)"
parallel $N php public/index.php cli/selftest apply "$PAY" refunded > /dev/null
expect "refunded 전이" 1 "$(st count refunded_transitions "$PAY")"
expect "환불 전환"     1 "$(st count conversions "$PAY" refund)"

echo "── 5. 코인 회수를 직접 두 번 — 멱등"
SECOND=$(st revoke "$PAY")
expect "두 번째 회수: 회수 0"          0 "$(echo "$SECOND" | sed 's/.*"revoked":\([0-9]*\).*/\1/')"
expect "두 번째 회수: '썼다' 로 안 읽힘" 0 "$(echo "$SECOND" | sed 's/.*"spent":\([0-9]*\).*/\1/')"
expect "두 번째 회수: 이미 회수됨 1"    1 "$(echo "$SECOND" | sed 's/.*"already":\([0-9]*\).*/\1/')"

echo "── 6. 결제 내부 대사 — 대조군의 코인 중복만 잡혀야 한다 (카카오페이 '피해 규모 모름')"
set +e
OUT=$(php public/index.php cli/verify payments 1 2>>"$IT_LOG")
RC=$?
set -e
echo "$OUT" | sed 's/^/     /'
expect "대사 종료 코드(어긋남 있음)" 1 "$RC"
expect "coin_duplicated"            1 "$(echo "$OUT" | grep -c '^  coin_duplicated ')"
expect "그 밖의 어긋남 종류"          1 "$(echo "$OUT" | grep -cE '^  [a-z_]+ +[0-9]+$')"

echo "── 7. 아웃박스 200건을 워커 4개가 동시에 (D-1)"
st enqueue 200 > /dev/null
worker() { for _ in 1 2 3 4 5 6 7 8; do CHANNELS=noop NOOP_OUTCOME=sent php public/index.php cli/dispatch once 20 > /dev/null 2>>"$IT_LOG"; done; }
worker & worker & worker & worker &
wait
expect "sent"                 200 "$(st count outbox sent)"
expect "pending"              0   "$(st count outbox pending)"
expect "같은 (행, 시도) 중복 기록" 0 "$(st count dispatch_duplicates)"

finish
