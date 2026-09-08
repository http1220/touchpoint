#!/bin/sh
# .env 가 기동 가능한 상태인지 본다.
#
#   sh scripts/check-env.sh [파일]
#
# 컨테이너를 띄우기 전에 도는 검사다. 값이 비어 있으면 MySQL 은
# 아예 안 뜨고, ENCRYPTION_KEY 가 비면 세션이 조용히 깨진다 —
# 둘 다 원인을 찾는 데 시간이 걸리는 종류라 앞에서 막는다.
#
# 값은 절대 출력하지 않는다. 길이와 줄 번호만 보여준다.
set -eu

ENV_FILE="${1:-.env}"

if [ ! -f "$ENV_FILE" ]; then
	echo "$ENV_FILE 이 없습니다. cp .env.example .env 부터 하세요." >&2
	exit 1
fi

awk '
# Compose 의 dotenv 파서와 같은 규칙으로 읽는다.
#   - 줄 앞 공백과 export 접두는 허용
#   - 값 뒤 " #" 부터는 주석
#   - 같은 키가 여러 번 나오면 뒤엣것이 이긴다
{
	line = $0
	sub(/\r$/, "", line)              # CRLF 로 저장된 경우
	sub(/^[ \t]+/, "", line)
	if (line ~ /^#/ || line == "") next
	sub(/^export[ \t]+/, "", line)

	p = index(line, "=")
	if (p == 0) next

	key = substr(line, 1, p - 1)
	v   = substr(line, p + 1)

	if (key ~ /[ \t]$/) {             # KEY =value — dotenv 에서 유효하지 않다
		gsub(/[ \t]+$/, "", key)
		badkey[key] = NR
	}

	# 주석 본문을 따로 보관한다. 값이 비었을 때 "혹시 주석 뒤에 썼나" 를
	# 짚어 주려면 주석 안을 들여다봐야 한다.
	cmt = ""
	if (match(v, /[ \t]+#/)) { cmt = substr(v, RSTART); v = substr(v, 1, RSTART - 1) }
	else if (v ~ /^[ \t]*#/) { cmt = v; v = "" }
	gsub(/^[ \t]+|[ \t]+$/, "", v)
	if (v ~ /^".*"$/ || v ~ /^'"'"'.*'"'"'$/) v = substr(v, 2, length(v) - 2)

	val[key] = v; seen[key] = 1; ln[key] = NR; comment[key] = cmt
}
END {
	n = split("SHOP_DOMAIN TRACK_DOMAIN ACME_EMAIL ACME_STAGING CI_ENVIRONMENT ENCRYPTION_KEY MYSQL_ROOT_PASSWORD MYSQL_PASSWORD REPL_PASSWORD", k, " ")
	req = "ACME_EMAIL CI_ENVIRONMENT ENCRYPTION_KEY MYSQL_ROOT_PASSWORD MYSQL_PASSWORD REPL_PASSWORD SHOP_DOMAIN"

	print ""
	printf "  %-22s %8s  %s\n", "키", "줄", "상태"
	printf "  %-22s %8s  %s\n", "----------------------", "--------", "------------------------"

	for (i = 1; i <= n; i++) {
		key = k[i]
		required = (index(req, key) > 0)
		v = val[key]

		if (!seen[key]) {
			printf "  %-22s %8s  %s\n", key, "-", "줄 자체가 없음"
			if (required) bad++
			continue
		}

		if (v == "" || v == "you@example.com") {
			state = (v == "") ? "비어 있음" : "예시 값 그대로"
			printf "  %-22s %8d  %s\n", key, ln[key], state
			if (required) {
				bad++
				# 원래 주석에는 없고 붙여넣은 값에만 있는 형태일 때만 짚는다.
				# 긴 hex 나 메일 주소가 주석 안에 있으면 그건 값이 밀려 들어간 것이다.
				c = tolower(comment[key])
				if (c ~ /[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]/ || c ~ /@[a-z0-9.-]+\.[a-z][a-z]+/)
					printf "  %-22s %8s  ↳ 값이 주석(#) 뒤에 있습니다. = 바로 뒤로 옮기세요\n", "", ""
			}
			continue
		}

		# 값은 찍지 않는다. 비밀값이 터미널 기록에 남으면 안 된다.
		if (key ~ /(PASSWORD|KEY|SECRET|TOKEN)$/)
			printf "  %-22s %8d  채워짐 (%d자)\n", key, ln[key], length(v)
		else
			printf "  %-22s %8d  %s\n", key, ln[key], v
	}
	print ""

	for (key in badkey) {
		printf "  형식 오류 %d번 줄: %s 의 = 앞에 공백이 있습니다. KEY=값 으로 붙여 쓰세요\n", badkey[key], key
		bad++
	}

	if (val["ENCRYPTION_KEY"] != "" && length(val["ENCRYPTION_KEY"]) < 32)
		print "  경고: ENCRYPTION_KEY 가 32자보다 짧습니다 (openssl rand -hex 16)"
	if (seen["CI_ENVIRONMENT"] && val["CI_ENVIRONMENT"] != "development" && val["CI_ENVIRONMENT"] != "production" && val["CI_ENVIRONMENT"] != "testing") {
		print "  CI_ENVIRONMENT 는 development / testing / production 중 하나여야 합니다"
		bad++
	}
	if (val["MYSQL_ROOT_PASSWORD"] != "" && val["MYSQL_ROOT_PASSWORD"] == val["MYSQL_PASSWORD"])
		print "  경고: root 와 앱 계정 비밀번호가 같습니다. 계정을 나눈 의미가 없어집니다"
	if (val["TRACK_DOMAIN"] == "")
		print "  참고: TRACK_DOMAIN 이 비어 있어 api. 수집 호스트 없이 뜹니다 (docs/setup.md 3-2)"
	if (val["ACME_STAGING"] == "")
		print "  경고: ACME_STAGING 이 비어 있습니다 — 프로덕션 인증서를 발급합니다. 처음이면 true 로 두세요"

	if (bad) { print ""; print "  " bad "건을 고쳐야 합니다."; exit 1 }
	print "  필수 값이 모두 채워졌습니다."
}
' "$ENV_FILE"

mode=$(stat -c '%a' "$ENV_FILE" 2>/dev/null || echo '')
case "$mode" in
	600|400|'') ;;
	*) echo "  경고: $ENV_FILE 권한이 $mode 입니다. chmod 600 $ENV_FILE" ;;
esac
