# 통합 테스트 공용 함수. POSIX sh — CI(ubuntu)와 알파인 컨테이너 둘 다에서 돈다.

# 프레임워크 로그(stderr)는 파일로 보낸다. 결과 줄이 묻히지 않게 — 실패하면 끝에 보여 준다.
IT_LOG=${TMPDIR:-/tmp}/tp-integration.$$.log
: > "$IT_LOG"

st() { php public/index.php cli/selftest "$@" 2>>"$IT_LOG"; }

FAILED=0

# expect <설명> <기대값> <실제값>
expect() {
	if [ "$2" = "$3" ]; then
		printf '  ✓ %s (%s)\n' "$1" "$3"
	else
		printf '  ✗ %s — 기대 %s, 실제 %s\n' "$1" "$2" "$3"
		FAILED=$((FAILED + 1))
	fi
}

# expect_ge <설명> <최소> <실제값>
expect_ge() {
	if [ "$3" -ge "$2" ]; then
		printf '  ✓ %s (%s ≥ %s)\n' "$1" "$3" "$2"
	else
		printf '  ✗ %s — %s 이상이어야 하는데 %s\n' "$1" "$2" "$3"
		FAILED=$((FAILED + 1))
	fi
}

# 같은 명령을 N개 프로세스로 동시에
parallel() {
	n=$1; shift
	seq 1 "$n" | xargs -P "$n" -I{} "$@" 2>>"$IT_LOG"
}

finish() {
	echo
	if [ "$FAILED" -eq 0 ]; then
		echo "통과"
		rm -f "$IT_LOG"
		exit 0
	fi
	echo "실패 $FAILED 건 — ERROR 로그:"
	grep '"level":"ERROR"' "$IT_LOG" | tail -30 || true
	exit 1
}
