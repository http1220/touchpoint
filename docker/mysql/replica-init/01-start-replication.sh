#!/bin/bash
# 복제 시작.
#
# GTID 기반이라 바이너리 로그 위치를 손으로 잡을 필요가 없다.
# SOURCE_AUTO_POSITION=1 이 알아서 따라잡는다.
#
# SOURCE_DELAY 를 주면 복제를 의도적으로 지연시킬 수 있다.
# read-after-write 이상을 재현할 때 쓴다 → docs/failure-scenarios.md E-1

# 이 파일은 MySQL 엔트리포인트가 "실행" 하거나 "source" 한다.
# 실행 비트가 없으면 source 되는데, 그러면 아래 셸 옵션이 엔트리포인트 쪽으로
# 새어 나간다. 엔트리포인트는 뒤에서 MYSQL_ONETIME_PASSWORD 같은 미설정 변수를
# 참조하므로 set -u 에 걸려 초기화가 거기서 끊긴다 — 실제로 그렇게 끊겼다.
#
# 그래서 본문을 서브셸로 감싼다. 실행되든 source 되든 옵션이 밖으로 나가지 않고,
# 실패는 서브셸의 종료 코드로 그대로 전달된다.
(
set -euo pipefail

SOURCE_HOST="${REPL_SOURCE_HOST:-mysql-primary}"
SOURCE_USER="${REPL_USER:-repl}"
DELAY="${REPL_DELAY_SECONDS:-0}"

# 기본값을 두지 않는다. 프라이머리와 복제본이 서로 다른 비밀번호를 쓰면
# 복제가 조용히 끊기고, 그 원인을 찾는 데 시간이 걸린다.
: "${REPL_PASSWORD:?REPL_PASSWORD 가 필요합니다. .env 에서 설정하세요}"
SOURCE_PW="${REPL_PASSWORD}"

echo "[replica] 프라이머리(${SOURCE_HOST}) 대기..."
for i in $(seq 1 60); do
  if mysqladmin ping -h "${SOURCE_HOST}" --silent 2>/dev/null; then
    echo "[replica] 프라이머리 준비됨"
    break
  fi
  sleep 2
done

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
STOP REPLICA;
RESET REPLICA ALL;

CHANGE REPLICATION SOURCE TO
  SOURCE_HOST        = '${SOURCE_HOST}',
  SOURCE_USER        = '${SOURCE_USER}',
  SOURCE_PASSWORD    = '${SOURCE_PW}',
  SOURCE_AUTO_POSITION = 1,
  SOURCE_DELAY       = ${DELAY},
  GET_SOURCE_PUBLIC_KEY = 1;

START REPLICA;
SQL

echo "[replica] 복제 시작. 지연 설정 = ${DELAY}초"
)
