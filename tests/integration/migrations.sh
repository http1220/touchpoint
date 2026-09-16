#!/bin/sh
# 마이그레이션 왕복 — down() 이 실제로 되돌리는가.
#
#   sh tests/integration/migrations.sh   (빈 DB 에서)
#
# RBS 2012: 문제가 난 업그레이드를 검증 없이 되돌려 두 번째 사고가 됐다
# → docs/incidents/payment-incidents.md 9. 이 저장소의 down() 12개도 CI 에서도
# 작업 기록에서도 실행한 흔적이 없었다 → docs/incidents/testable-cases.md 8장
#
#   ① 빈 DB → 최신 → 스키마 A
#   ② 전부 내림(0) → 테이블 0개
#   ③ 다시 최신 → 스키마가 A 와 같은가
#   ④ 마이그레이션마다 그 직전 버전까지 내렸다 올려도 A 와 같은가
set -eu
cd "$(dirname "$0")/../.."
. tests/integration/lib.sh

TMP=${TMPDIR:-/tmp}/tp-migrations.$$
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT

mig() { php public/index.php cli/migrate "$@" > /dev/null 2>>"$IT_LOG"; }

VERSIONS=$(ls application/migrations | sed -n 's/^\([0-9]\{14\}\)_.*\.php$/\1/p' | sort)
COUNT=$(echo "$VERSIONS" | wc -l | tr -d ' ')

echo "── 1. 빈 DB → 최신 (${COUNT}개)"
mig latest
st schema > "$TMP/a.txt"
expect_ge "테이블" 1 "$(st count tables)"

echo "── 2. 전부 내림"
mig to 0
expect "남은 테이블" 0 "$(st count tables)"

echo "── 3. 다시 최신 — 스키마가 같은가"
mig latest
st schema > "$TMP/b.txt"
if diff -u "$TMP/a.txt" "$TMP/b.txt"; then
	expect "처음과 같은 스키마" same same
else
	expect "처음과 같은 스키마" same different
fi

echo "── 4. 마이그레이션마다 그 직전 버전까지 내렸다 다시 올리기"
PREV=0
for V in $VERSIONS; do
	# V 를 되돌리려면 V 직전 버전으로 내린다
	if mig to "$PREV" && mig latest; then
		st schema > "$TMP/c.txt"
		if diff -q "$TMP/a.txt" "$TMP/c.txt" > /dev/null; then
			expect "$V 왕복" same same
		else
			diff -u "$TMP/a.txt" "$TMP/c.txt" | head -20
			expect "$V 왕복" same different
		fi
	else
		expect "$V 왕복 실행" ok failed
	fi
	PREV=$V
done

finish
