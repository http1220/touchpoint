#!/bin/bash
# 복제 계정 생성.
#
# SQL 파일이 아니라 셸 스크립트인 이유:
# 비밀번호를 파일에 박아 두면 .env 에서 바꿔도 프라이머리는 옛 비밀번호를 유지해
# 복제가 조용히 끊긴다. 환경변수에서 읽어야 한쪽만 바뀌는 일이 없다.
# 그리고 공개 저장소에 비밀번호 문자열이 남지 않는다.

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
