#!/bin/bash
# 1사이클 데모 — 광고 클릭부터 매체 전송·대사까지 운영 서버에서 한 번 관통한다.
#
#   bash scripts/demo-cycle.sh            (서버의 저장소 루트에서)
#
# 운영 매체를 오염시키지 않는다.
#   - 데모 동안 상시 워커를 멈춘다. 데모 전환은 일회성 워커가 한 번만 보낸다
#   - 그 한 번은 GA4 검증 주소(/debug/mp/collect, 적재 안 됨)와
#     Meta 테스트 이벤트 코드로만 나간다
#   - 운영 .env 는 바꾸지 않는다. 끝나면(실패해도) 워커를 다시 올린다
#
# 브라우저 없이 curl 로 돈다. 그래서 gtag·Pixel(브라우저 태그)은 나가지 않는다 —
# 이 데모가 보여 주는 것은 서버 쪽 경로다.
set -uo pipefail
cd "$(dirname "$0")/.."
exec </dev/null

SHOP=$(grep '^SHOP_DOMAIN=' .env | cut -d= -f2 | awk '{print $1}')
META_TEST=$(grep -h '^META_TEST_EVENT_CODE=' .env.demo 2>/dev/null .env.bak-20260915-prod-meta 2>/dev/null | head -1 | cut -d= -f2)
UA='Mozilla/5.0 (touchpoint-demo) Chrome/130'
JAR=$(mktemp); OUT=$(mktemp)
T=$(date +%s)

q()   { docker compose exec -T mysql-primary sh -c 'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" attribution -e "$1"' sh "$1" 2>/dev/null; }
say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()  { printf '  ✓ %s\n' "$*"; }
bad() { printf '  ✗ %s\n' "$*"; FAIL=$((FAIL+1)); }
FAIL=0

restore() {
  docker compose --profile worker up -d worker >/dev/null 2>&1
  rm -f "$JAR" "$OUT"
  printf '\n상시 워커 복구: %s\n' "$(docker compose ps --format '{{.Service}} {{.Status}}' worker 2>/dev/null)"
}
trap restore EXIT

say "0. 준비 — 상시 워커를 잠시 멈춘다 (데모 전환이 운영 매체로 나가지 않게)"
docker compose stop worker >/dev/null 2>&1 && ok "worker 정지"
[ -n "$META_TEST" ] && ok "Meta 테스트 이벤트 코드 준비됨" || bad "Meta 테스트 이벤트 코드 없음 — Meta 는 dead 로 남긴다"

# ─────────────────────────────────────────────────────────────
say "1. 광고 클릭 — lp.$SHOP/go (utm · gclid 가 붙은 광고 링크)"
curl -s -c "$JAR" -A "$UA" -o /dev/null -D "$OUT" \
  "https://lp.$SHOP/go?work=3&pid=demo_ads&utm_source=demo&utm_medium=cpc&utm_campaign=cycle-$T&gclid=DEMO$T"
STATUS=$(head -1 "$OUT" | awk '{print $2}')
LOC=$(grep -i '^location:' "$OUT" | cut -d' ' -f2 | tr -d '\r')
CC=$(grep -i '^cache-control:' "$OUT" | cut -d' ' -f2- | tr -d '\r')
[ "$STATUS" = 302 ] && ok "302 → $LOC" || bad "상태 $STATUS"
[ "$CC" = "no-store" ] && ok "Cache-Control: no-store (301 캐시 사고 방지 — C-1)" || bad "캐시 헤더: $CC"
VID=$(awk '$6=="ab_vid"{print $7}' "$JAR")
[ -n "$VID" ] && ok "방문 쿠키 ab_vid=${VID:0:12}… (등록 도메인 .$SHOP)" || bad "방문 쿠키 없음"

say "2. 랜딩 — 리다이렉트를 따라간다"
curl -s -b "$JAR" -c "$JAR" -A "$UA" -o "$OUT" -w '%{http_code}' "$LOC" > "$OUT.code"
[ "$(cat "$OUT.code")" = 200 ] && ok "랜딩 200 ($(grep -o '<title>[^<]*' "$OUT" | sed 's/<title>//'))" || bad "랜딩 $(cat "$OUT.code")"
q "SELECT t.position, t.pid, t.utm_source, t.utm_campaign, LEFT(t.gclid,12) gclid FROM touchpoints t JOIN visits v ON v.id=t.visit_id WHERE v.visit_uid=UNHEX('$VID') ORDER BY t.position" | sed 's/^/    /'

say "3. 수집 — 다른 오리진(api.)으로 페이지뷰·배너 노출 (CORS)"
PRE=$(curl -s -o /dev/null -D - -X OPTIONS "https://api.$SHOP/collect" -H "Origin: https://lp.$SHOP" -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: content-type')
echo "$PRE" | grep -qi "access-control-allow-origin: https://lp.$SHOP" && ok "preflight: 오리진 정확히 반향 (* 아님 — B-2)" || bad "preflight 헤더"
C=$(curl -s -b "$JAR" -A "$UA" -o /dev/null -w '%{http_code}' "https://api.$SHOP/collect" -H "Origin: https://lp.$SHOP" -H 'Content-Type: application/json' --data "{\"event\":\"page_view\",\"work_id\":\"3\"}")
[ "$C" = 200 ] && ok "page_view 200" || bad "collect $C"
I=$(curl -s -b "$JAR" -A "$UA" "https://api.$SHOP/impression" -H "Origin: https://lp.$SHOP" -H 'Content-Type: text/plain;charset=UTF-8' --data '{"items":[{"work_id":1,"slot":"lp_related"},{"work_id":2,"slot":"lp_related"}]}')
echo "$I" | grep -q '"accepted":2' && ok "배너 노출 배치 2건 (beacon 형식)" || bad "impression $I"
CL=$(curl -s -b "$JAR" -A "$UA" -o /dev/null -D - "https://api.$SHOP/click?w=2&s=lp_related&sd=$(date -u +%Y%m%d)&u=https%3A%2F%2Flp.$SHOP%2Fl%2F2" | grep -i '^location:' | tr -d '\r')
[ -n "$CL" ] && ok "배너 클릭 302 ${CL#*: }" || bad "click"

say "4. 결제 시작 — app./purchase (브라우저 Origin · Referer · 방문 쿠키)"
USER=$(docker compose run --rm --no-deps -T -e SEED_ALLOWED=true app php public/index.php cli/seed user 2>/dev/null | sed -n 's/^user_uid=//p' | tr -d '\r')
[ -n "$USER" ] && ok "데모 회원 ${USER:0:12}…" || bad "회원 생성"
P=$(curl -s -b "$JAR" -A "$UA" "https://app.$SHOP/purchase" -H 'Content-Type: application/json' \
  -H "Origin: https://app.$SHOP" -H "Referer: https://app.$SHOP/coins/checkout?promo=demo" \
  --data "{\"product\":\"coin_100\",\"amount_minor\":9900,\"currency\":\"KRW\",\"idempotency_key\":\"demo-$T\",\"user_uid\":\"$USER\"}")
PAY=$(echo "$P" | sed -n 's/.*"payment_uid":"\([0-9a-f]*\)".*/\1/p')
[ -n "$PAY" ] && ok "결제 created · payment_uid ${PAY:0:12}…" || bad "purchase $P"
R=$(curl -s -b "$JAR" -A "$UA" "https://app.$SHOP/purchase" -H 'Content-Type: application/json' \
  --data "{\"product\":\"coin_100\",\"amount_minor\":9900,\"currency\":\"KRW\",\"idempotency_key\":\"demo-$T\",\"user_uid\":\"$USER\"}")
echo "$R" | grep -q '"duplicated":true' && ok "같은 결제 버튼 한 번 더 → 200 duplicated (멱등 키)" || bad "재요청 $R"
q "SELECT c.event_source_url url, c.client_user_agent ua, c.client_ip_address IS NOT NULL has_ip, p.visit_id IS NOT NULL has_visit FROM payments p LEFT JOIN payment_client_context c ON c.payment_id=p.id WHERE p.payment_uid=UNHEX('$PAY')" | sed 's/^/    /'

say "5. PG 웹훅 — 순서 뒤집힘 · 중복까지 (서명 검증 · 조건부 UPDATE)"
docker compose exec -T app php public/index.php cli/pg send "$PAY" authorized 2>/dev/null | sed 's/^/    /'
docker compose exec -T app php public/index.php cli/pg send "$PAY" captured   2>/dev/null | sed 's/^/    /'
docker compose exec -T app php public/index.php cli/pg send "$PAY" captured   2>/dev/null | sed 's/^/    /'
docker compose exec -T app php public/index.php cli/pg send "$PAY" authorized 2>/dev/null | sed 's/^/    /'
BADSIG=$(curl -s -o /dev/null -w '%{http_code}' "https://app.$SHOP/webhooks/pg" -H 'X-PG-Signature: t=1,v1=00' --data "{\"payment_uid\":\"$PAY\",\"status\":\"refunded\"}")
[ "$BADSIG" = 401 ] && ok "위조 서명 환불 → 401" || bad "위조 서명 $BADSIG"
q "SELECT (SELECT status FROM payments WHERE payment_uid=UNHEX('$PAY')) status, (SELECT COUNT(*) FROM coin_lots l JOIN payments p ON p.id=l.payment_id WHERE p.payment_uid=UNHEX('$PAY')) coin_lots, (SELECT COUNT(*) FROM conversions WHERE dedup_key='purchase:$PAY') conversions" | sed 's/^/    /'

say "6. 아웃박스 — 전환과 '매체에 보내라' 가 같은 트랜잭션에"
q "SELECT o.id, o.channel, o.status, JSON_LENGTH(o.payload) payload_keys, JSON_UNQUOTE(JSON_EXTRACT(o.payload,'\$.event_source_url')) url FROM dispatch_outbox o JOIN conversions c ON c.id=o.conversion_id WHERE c.dedup_key='purchase:$PAY'" | sed 's/^/    /'

say "7. 매체 전송 — 일회성 워커 (GA4 검증 주소 · Meta 테스트 이벤트)"
# 일회성 워커는 대기 중인 행을 가리지 않고 가져간다. 데모 동안 들어온 진짜 전환이
# 섞여 있으면 그것까지 테스트 주소로 나가 버린다 — 그때는 보내지 않고 멈춘다.
OTHERS=$(q "SELECT COUNT(*) FROM dispatch_outbox o JOIN conversions c ON c.id=o.conversion_id WHERE o.status IN ('pending','failed') AND c.dedup_key <> 'purchase:$PAY'" | tail -1)
if [ "$OTHERS" != 0 ]; then
  bad "데모가 아닌 대기 전환 ${OTHERS}건이 있어 전송을 건너뛴다 (상시 워커가 운영으로 보낸다)"
else
  docker compose run --rm --no-deps -T -e GA4_DEBUG=true -e META_TEST_EVENT_CODE="$META_TEST" worker \
    php public/index.php cli/dispatch once 2>/dev/null | grep -E '→ (sent|dead|retry|failed)' | sed 's/^/    /'
fi
q "SELECT o.channel, o.status, l.http_status, l.send_ms FROM dispatch_outbox o JOIN conversions c ON c.id=o.conversion_id LEFT JOIN dispatch_log l ON l.outbox_id=o.id WHERE c.dedup_key='purchase:$PAY'" | sed 's/^/    /'

say "8. 귀속 — 이 매출은 어느 광고 것인가"
q "SELECT t.position, t.pid, t.utm_campaign FROM conversions c JOIN touchpoints t ON t.visit_id=c.visit_id WHERE c.dedup_key='purchase:$PAY' ORDER BY t.position" | sed 's/^/    /'

say "9. 대사 — 결제 ↔ 코인 ↔ 전환"
docker compose exec -T app php public/index.php cli/verify payments 1 2>/dev/null | sed 's/^/    /'

say "10. 지표 화면 — app.$SHOP/metrics"
curl -s "https://app.$SHOP/metrics" | grep -A12 '자리별 CTR' | grep -oE '<code>[0-9-]{10}</code>|<code>lp_related</code>|<td class="n">[0-9]+|[0-9.]+%' | tr '\n' ' ' | sed 's/<[^>]*>//g; s/^/    CTR 표: /'; echo

say "끝 — 실패 $FAIL 건"
echo "  Meta: Events Manager → 테스트 이벤트 탭에서 event_id $(q "SELECT LOWER(HEX(conversion_uid)) FROM conversions WHERE dedup_key='purchase:$PAY'" | tail -1)"
