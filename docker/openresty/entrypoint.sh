#!/bin/sh
# OpenResty 기동 전 설정 생성.
#
# 두 가지 조건에 따라 서버 블록을 만들거나 만들지 않는다.
#
#   ① TRACK_DOMAIN 이 있는가   — 없으면 api. 수집 호스트가 없다
#   ② 인증서가 실재하는가      — 없으면 그 도메인의 443 블록을 만들지 않는다
#
# ②가 이 스크립트의 핵심이다. nginx 는 ssl_certificate 파일이 없으면
# 설정 파싱 단계에서 기동을 거부한다. 그런데 인증서를 받으려면
# ACME 챌린지를 80번으로 받아야 하고, 그 80번 블록도 같은 설정 파일에 있다.
#
#   인증서가 없다 → nginx 가 안 뜬다 → 80번이 안 열린다 → 인증서를 못 받는다
#
# 이 순환을 끊으려고 443 블록을 별도 파일로 뺐다. 첫 기동은 HTTP 로만 뜨고,
# certbot 이 인증서를 받은 뒤 엣지를 재기동하면 HTTPS 가 붙는다.
#
# 치환에 envsubst 를 쓰지 않는다. openresty/openresty:alpine 에 gettext 가
# 없어서 "envsubst: not found" 로 죽는다. 도메인 이름은 sed 구분자로 쓰는
# '|' 를 포함할 수 없어 안전하다.
set -eu

NGX_HOME=/usr/local/openresty/nginx
SERVERS="$NGX_HOME/servers"
TEMPLATES="$NGX_HOME/templates"
LE=/etc/letsencrypt/live

: "${SHOP_DOMAIN:?SHOP_DOMAIN 이 필요합니다}"
TRACK_DOMAIN="${TRACK_DOMAIN:-}"

# 도메인 형식 검증. 여기서 막지 않으면 잘못된 값이 설정 파일에 그대로 박혀
# nginx 문법 오류로 나타나고, 원인이 .env 라는 걸 알아채기 어려워진다.
for d in "$SHOP_DOMAIN" ${TRACK_DOMAIN:+$TRACK_DOMAIN}; do
	case "$d" in
		*[!a-zA-Z0-9.-]*|-*|.*|*.|"")
			echo "openresty: 도메인 형식이 올바르지 않습니다: '$d'" >&2
			exit 1
			;;
	esac
done

mkdir -p "$SERVERS"
rm -f "$SERVERS"/*.https.conf "$SERVERS/http-root.inc"

sed "s|\${SHOP_DOMAIN}|$SHOP_DOMAIN|g" \
	< "$NGX_HOME/conf/nginx.template" \
	> "$NGX_HOME/conf/nginx.conf"

has_cert() { [ -s "$LE/$1/fullchain.pem" ] && [ -s "$LE/$1/privkey.pem" ]; }

https=0

# ── 광고주 측: 루트 · lp. · m. · app. ──────────────────────
if has_cert "$SHOP_DOMAIN"; then
	sed "s|\${SHOP_DOMAIN}|$SHOP_DOMAIN|g" \
		< "$TEMPLATES/shop.template" > "$SERVERS/shop.https.conf"
	echo "openresty: HTTPS 활성 — $SHOP_DOMAIN (루트 · lp. · m. · app.)"
	https=1
else
	echo "openresty: $SHOP_DOMAIN 인증서가 없어 HTTP 로만 뜹니다."
	echo "openresty:   docker compose --profile cert run --rm certbot"
	echo "openresty:   docker compose restart openresty"
fi

# ── 추적 측: api. ──────────────────────────────────────────
if [ -z "$TRACK_DOMAIN" ]; then
	echo "openresty: TRACK_DOMAIN 미설정 — 수집 호스트 없이 뜹니다 (ADR-002)"
elif has_cert "$TRACK_DOMAIN"; then
	sed "s|\${TRACK_DOMAIN}|$TRACK_DOMAIN|g" \
		< "$TEMPLATES/track.template" > "$SERVERS/track.https.conf"
	echo "openresty: HTTPS 활성 — api.$TRACK_DOMAIN"
	https=1
else
	echo "openresty: api.$TRACK_DOMAIN 인증서가 없어 건너뜁니다."
fi

# ── 80번 블록의 기본 경로 ──────────────────────────────────
# 인증서가 하나도 없는데 https 로 리다이렉트하면 사용자는 연결 실패만 본다.
# 무엇이 덜 됐는지 말해 주는 편이 낫다.
if [ "$https" = 1 ]; then
	cat > "$SERVERS/http-root.inc" <<'INNER'
location / {
    return 301 https://$host$request_uri;
}
INNER
else
	cat > "$SERVERS/http-root.inc" <<'INNER'
location / {
    default_type text/plain;
    add_header Cache-Control "no-store" always;
    return 503 "TLS certificate not issued yet.\nRun: docker compose --profile cert run --rm certbot\nThen: docker compose restart openresty\n";
}
INNER
fi

exec openresty -g 'daemon off;'
