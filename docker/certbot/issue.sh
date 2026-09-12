#!/bin/sh
# 인증서 발급. docker compose --profile cert run --rm certbot
#
# 도메인마다 별도 인증서를 만든다. 하나로 묶지 않는 이유는 수명이다 —
# 추적 도메인은 나중에 등록될 수도 있고, 프로젝트가 끝나면 먼저 내린다.
# 묶어 두면 한쪽 때문에 다른 쪽까지 재발급해야 한다.
set -eu

: "${ACME_EMAIL:?ACME_EMAIL 이 필요합니다}"
: "${SHOP_DOMAIN:?SHOP_DOMAIN 이 필요합니다}"
TRACK_DOMAIN="${TRACK_DOMAIN:-}"

# 비어 있지 않으면 스테이징. 처음에는 반드시 채운다 —
# 프로덕션은 도메인당 주 5회 제한이라 시행착오로 소모하면 일주일을 날린다.
STAGING=""
[ -n "${ACME_STAGING:-}" ] && STAGING="--staging"

issue() {
	name="$1"
	shift
	# shellcheck disable=SC2086
	certbot certonly --webroot -w /var/www/certbot $STAGING \
		--email "$ACME_EMAIL" --agree-tos --no-eff-email \
		--cert-name "$name" "$@"
}

echo "== $SHOP_DOMAIN (루트 + lp. + m. + app. + api.) =="
issue "$SHOP_DOMAIN" \
	-d "$SHOP_DOMAIN" \
	-d "lp.$SHOP_DOMAIN" \
	-d "m.$SHOP_DOMAIN" \
	-d "app.$SHOP_DOMAIN" \
	-d "api.$SHOP_DOMAIN"

if [ -z "$TRACK_DOMAIN" ]; then
	echo "== 추적 도메인 미설정 — 건너뜁니다 =="
	echo "   등록 후 .env 의 TRACK_DOMAIN 을 채우고 이 명령을 다시 실행하세요."
	exit 0
fi

echo "== $TRACK_DOMAIN (api.) =="
issue "$TRACK_DOMAIN" -d "api.$TRACK_DOMAIN"
