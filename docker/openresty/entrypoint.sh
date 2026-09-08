#!/bin/sh
# OpenResty 기동 전 설정 생성.
#
# 하는 일은 둘이다.
#   ① nginx.template 의 ${SHOP_DOMAIN} 치환
#   ② TRACK_DOMAIN 이 있을 때만 api. 서버 블록 생성
#
# ②가 이 스크립트가 존재하는 이유다. 추적 도메인 등록이 늦어져도
# 광고주 측 호스트는 먼저 띄울 수 있어야 하는데, nginx 는 조건부 블록이
# 없어서 설정 파일 자체를 나눠야 한다.
#
# 치환에 envsubst 를 쓰지 않는다. openresty/openresty:alpine 에 gettext 가
# 없어서 "envsubst: not found" 로 죽는다. 기동할 때마다 apk add 를 하면
# 네트워크에 의존하게 되므로 sed 로 한다. 도메인 이름은 sed 구분자로
# 쓰는 '|' 를 포함할 수 없어 안전하다.
set -eu

NGX_HOME=/usr/local/openresty/nginx
SERVERS_DIR="$NGX_HOME/servers"

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

mkdir -p "$SERVERS_DIR"

sed "s|\${SHOP_DOMAIN}|$SHOP_DOMAIN|g" \
	< "$NGX_HOME/conf/nginx.template" \
	> "$NGX_HOME/conf/nginx.conf"

if [ -n "$TRACK_DOMAIN" ]; then
	sed "s|\${TRACK_DOMAIN}|$TRACK_DOMAIN|g" \
		< "$NGX_HOME/templates/track.template" \
		> "$SERVERS_DIR/track.conf"
	echo "openresty: 추적 호스트 api.$TRACK_DOMAIN 활성화"
else
	# 빈 파일이 아니라 이유를 적어 둔다. 나중에 왜 api. 가 없는지
	# 찾을 때 이 파일이 첫 단서가 된다.
	cat > "$SERVERS_DIR/track.conf" <<-INNER
	# TRACK_DOMAIN 이 비어 있어 수집 호스트를 만들지 않았습니다.
	# 크로스사이트 실험(서드파티 쿠키·CORS preflight·SameSite=None)은
	# 이 상태에서 성립하지 않습니다. 등록 도메인이 두 개여야 합니다.
	#   → docs/decisions/ADR-002-two-registered-domains.md
	INNER
	echo "openresty: TRACK_DOMAIN 미설정 — 수집 호스트 없이 기동합니다"
fi

exec openresty -g 'daemon off;'
