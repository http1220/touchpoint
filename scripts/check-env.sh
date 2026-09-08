#!/bin/sh
# .env 가 기동 가능한 상태인지 본다.
#
#   sh scripts/check-env.sh
#
# 컨테이너를 띄우기 전에 도는 검사다. 값이 비어 있으면 MySQL 은
# 아예 안 뜨고, ENCRYPTION_KEY 가 비면 세션이 조용히 깨진다 —
# 둘 다 원인을 찾는 데 시간이 걸리는 종류라 앞에서 막는다.
set -eu

ENV_FILE="${1:-.env}"

if [ ! -f "$ENV_FILE" ]; then
	echo "$ENV_FILE 이 없습니다. cp .env.example .env 부터 하세요." >&2
	exit 1
fi

# 값 뒤 인라인 주석은 떼고 본다. Compose 의 dotenv 파서와 같은 규칙이고,
# 같은 키가 여러 번 나오면 뒤에 나온 것이 이긴다.
awk '
{
	p = index($0, "=")
	if (p == 0) next
	key = substr($0, 1, p - 1)
	if (key ~ /^[ \t]*#/) next
	v = substr($0, p + 1)
	sub(/[ \t]+#.*/, "", v)
	gsub(/^[ \t]+|[ \t]+$/, "", v)
	val[key] = v
	seen[key] = 1
}
END {
	n = split("SHOP_DOMAIN ACME_EMAIL CI_ENVIRONMENT ENCRYPTION_KEY MYSQL_ROOT_PASSWORD MYSQL_PASSWORD REPL_PASSWORD", k, " ")
	for (i = 1; i <= n; i++) {
		key = k[i]
		if (!seen[key] || val[key] == "" || val[key] == "you@example.com") {
			print "  채워야 함: " key
			bad++
		}
	}

	# 흔한 오타·함정
	if (val["ENCRYPTION_KEY"] != "" && length(val["ENCRYPTION_KEY"]) < 32)
		print "  경고: ENCRYPTION_KEY 가 32자보다 짧습니다 (openssl rand -hex 16)"
	if (val["CI_ENVIRONMENT"] != "development" && val["CI_ENVIRONMENT"] != "production" && val["CI_ENVIRONMENT"] != "testing") {
		print "  CI_ENVIRONMENT 는 development / testing / production 중 하나여야 합니다: [" val["CI_ENVIRONMENT"] "]"
		bad++
	}
	if (val["TRACK_DOMAIN"] == "")
		print "  참고: TRACK_DOMAIN 이 비어 있어 api. 수집 호스트 없이 뜹니다 (docs/setup.md 3-2)"
	if (val["ACME_STAGING"] == "")
		print "  경고: ACME_STAGING 이 비어 있습니다 — 프로덕션 인증서를 발급합니다. 처음이면 true 로 두세요"

	if (bad) exit 1
	print "  필수 값이 모두 채워졌습니다"
}
' "$ENV_FILE"

# 권한. 비밀값이 들어 있는 파일이다.
if [ "$(uname -s)" != "MINGW64_NT-10.0" ]; then
	mode=$(stat -c '%a' "$ENV_FILE" 2>/dev/null || echo '')
	case "$mode" in
		600|400) ;;
		'')      ;;
		*)       echo "  경고: $ENV_FILE 권한이 $mode 입니다. chmod 600 $ENV_FILE" ;;
	esac
fi
