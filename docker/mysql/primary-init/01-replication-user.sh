#!/bin/bash
# 복제 계정 생성.
#
# SQL 파일이 아니라 셸 스크립트인 이유:
# 비밀번호를 파일에 박아 두면 .env 에서 바꿔도 프라이머리는 옛 비밀번호를 유지해
# 복제가 조용히 끊긴다. 환경변수에서 읽어야 한쪽만 바뀌는 일이 없다.
# 그리고 공개 저장소에 비밀번호 문자열이 남지 않는다.

# 이 파일은 MySQL 엔트리포인트가 "실행" 하거나 "source" 한다.
# 실행 비트가 없으면 source 되는데, 그러면 아래 셸 옵션이 엔트리포인트 쪽으로
# 새어 나간다. 엔트리포인트는 뒤에서 MYSQL_ONETIME_PASSWORD 같은 미설정 변수를
# 참조하므로 set -u 에 걸려 초기화가 거기서 끊긴다 — 실제로 그렇게 끊겼다.
#
# 그래서 본문을 서브셸로 감싼다. 실행되든 source 되든 옵션이 밖으로 나가지 않고,
# 실패는 서브셸의 종료 코드로 그대로 전달된다.
(
set -euo pipefail

: "${REPL_PASSWORD:?REPL_PASSWORD 가 필요합니다. .env 에서 설정하세요}"
APP_USER="${MYSQL_USER:-ab}"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
-- 복제본이 이 계정으로 바이너리 로그를 읽어간다.
CREATE USER IF NOT EXISTS 'repl'@'%'
  IDENTIFIED WITH caching_sha2_password BY '${REPL_PASSWORD}';
ALTER USER 'repl'@'%'
  IDENTIFIED WITH caching_sha2_password BY '${REPL_PASSWORD}';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';

-- 앱 계정은 두 서버 모두에서 통해야 한다(복제본은 읽기 전용).
GRANT SELECT, INSERT, UPDATE, DELETE ON *.* TO '${APP_USER}'@'%';

FLUSH PRIVILEGES;
SQL

echo "[primary] 복제 계정 준비 완료"
)
