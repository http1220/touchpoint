# 인프라 구축 절차 (D1~D2)

목표: **호스트 4개(`lp.` `m.` `app.` `api.`)가 자물쇠 표시로 열리는 진단 화면.** 여기까지가 D1~D2다.
그 다음이 CodeIgniter 3 기동과 스키마 마이그레이션이다.

---

## 0. 준비물

| 항목 | 비고 |
|---|---|
| **등록 도메인 2개** | 서브도메인만 나누면 실험이 성립하지 않는다 → [ADR-002](decisions/ADR-002-two-registered-domains.md) · 등록 절차는 [domain-setup.md](domain-setup.md) |
| AWS 계정 | 없으면 카드 등록·본인인증에 반나절 |
| GA4 속성 | `measurement_id` + **API secret** (D12에 필요, 리드타임 대비 미리) |

> ⚠️ **TLD를 아끼지 않는다.** 저가 TLD는 광고 차단기·기업 DNS에서 통째로 차단되는 일이 있어, 이 프로젝트에서는 **TLD가 실험 변수가 된다.** 요청이 막혔을 때 `SameSite` 때문인지 TLD 때문인지 구분할 수 없게 된다 → [domain-setup.md](domain-setup.md) 2장

---

## 1. DNS

두 도메인의 A 레코드를 **같은 EIP**로 향하게 한다.

```
lp.<SHOP_DOMAIN>     A   <EIP>
app.<SHOP_DOMAIN>    A   <EIP>
api.<TRACK_DOMAIN>   A   <EIP>
```

전파 확인:

```bash
dig +short lp.<SHOP_DOMAIN> app.<SHOP_DOMAIN> api.<TRACK_DOMAIN>
```

> **세 줄이 모두 같은 EIP를 뱉을 때까지 다음 단계로 넘어가지 않는다.** DNS가 안 된 상태로 certbot을 돌리면 ACME 검증이 실패하면서 rate limit만 소모한다.

---

## 2. EC2

**t3.small.** t3.micro(1GB)로는 MySQL 2대가 올라가지 않는다 → [ADR-007](decisions/ADR-007-read-write-split.md)

> 2025-07-15 이후 신규 AWS 계정은 750시간 프리티어가 아니라 **6개월간 $200 크레딧** 방식이다. t3.small 2주는 이 크레딧 안에서 처리된다.

보안 그룹은 **22 / 80 / 443**만 연다.

### swap 2GB — 건너뛰면 MySQL이 죽는다

RAM 2GB에 MySQL 2대 + PHP-FPM + OpenResty를 올린다. 컨테이너 메모리 상한 합계가 약 1.2GB라 여유가 크지 않다. swap 없이 워커까지 4개로 늘리면 OOM 위험이 있다.

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h          # Swap 행이 2.0Gi 로 잡히는지 확인
```

### Docker

```bash
sudo dnf install -y docker git          # Amazon Linux 2023
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
# 재로그인 후
docker compose version
```

---

## 3. 저장소와 환경 설정

```bash
git clone <repo> touchpoint
cd touchpoint
cp .env.example .env
```

`.env`에서 반드시 채울 것:

| 키 | 값 |
|---|---|
| `SHOP_DOMAIN` / `TRACK_DOMAIN` | 실제 구매 도메인 |
| `ACME_EMAIL` | 인증서 만료 알림 수신 주소 |
| `MYSQL_ROOT_PASSWORD` / `MYSQL_PASSWORD` | 직접 생성 |
| `ACME_STAGING` | **처음에는 `true`** |

---

## 4. ACME 스테이징으로 먼저 검증

**이 단계를 건너뛰지 않는다.** Let's Encrypt 프로덕션은 도메인당 **주 5회** 제한이 있고, 설정 시행착오로 소모하면 일주일을 날린다.

인증서 발급은 certbot(webroot)으로 한다. `.env` 의 `ACME_STAGING` 이 비어 있지 않으면 `--staging` 이 붙는다.

```bash
# .env 에 ACME_STAGING=true 인지 확인
docker compose up -d openresty          # 80 포트로 ACME 챌린지를 받는다
docker compose --profile cert run --rm certbot
```

두 도메인에 각각 발급된다 — `SHOP_DOMAIN`(루트 + `lp.` `m.` `app.`)과 `TRACK_DOMAIN`(`api.`).

```bash
curl -kI https://lp.<SHOP_DOMAIN>
# 200 이 오면 성공. 인증서 경고(-k)는 스테이징 CA라 정상이다.
```

> 발급 실패 시 `openresty` 컨테이너 로그에서 `/.well-known/acme-challenge/` 요청이 **200 으로** 찍혔는지 본다. 404 라면 DNS 나 webroot 볼륨 문제다.

---

## 5. 프로덕션 인증서로 전환

스테이징이 네 호스트 모두 성공했으면:

```bash
# 1) .env 에서 ACME_STAGING 을 비운다 (ACME_STAGING=)
# 2) 스테이징 인증서를 버린다
docker compose down
docker volume rm touchpoint_certbot_conf
# 3) 재발급
docker compose up -d openresty
docker compose --profile cert run --rm certbot
docker compose restart openresty
```

갱신은 cron 으로 돌린다. 인증서는 90일이고, 잊으면 조용히 만료된다.

```bash
# crontab -e  (매주 월요일 03:17 — 정각을 피해 ACME 서버 부하 분산)
17 3 * * 1 cd /home/ec2-user/touchpoint && docker compose --profile cert run --rm certbot renew && docker compose restart openresty
```

---

## 6. 애플리케이션 기동과 마이그레이션

```bash
docker compose up -d
docker compose exec app php public/index.php cli/migrate latest
docker compose exec app php public/index.php cli/migrate current   # 20260909000700
```

복제가 붙었는지 확인한다. **여기서 `Slave_IO_Running`/`Slave_SQL_Running` 이 둘 다 `Yes` 가 아니면 읽기 분리 실험 전체가 성립하지 않는다.**

```bash
docker compose exec mysql-replica \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW REPLICA STATUS\G" \
  | grep -E "Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source"
```

---

## 7. 검증 — 여기까지가 D1~D2 완료 조건

| # | 확인 | 방법 |
|---|---|---|
| 1 | 네 호스트가 **자물쇠 표시**로 열림 | `lp.` `m.` `app.` `api.` 각각 브라우저로 |
| 2 | 진단 페이지의 **역할 판별**이 맞음 | `/diag` 에서 `lp` / `m` / `app` / `api` 로 표시되는지 |
| 3 | 인증서가 두 도메인에 각각 발급됨 | `curl -vI https://... 2>&1 \| grep -i "issuer\|subject"` |
| 4 | HTTP/2 로 응답 | `/diag` 의 `HTTP 버전` 행 |
| 5 | **쿠키 속성이 정책표와 일치** | DevTools → Application → Cookies 에서 `tp_probe_*` |
| 6 | `api.` 쿠키에 `Partitioned` 가 붙음 | 위와 동일. `SameSite=None; Secure` 와 함께여야 효력 |
| 7 | **읽기 대상이 요청마다 갈림** | 쿠키를 지우고 `/diag` 를 여러 번 → `읽기 대상` 이 rdb1/rdb2 로 번갈아 |
| 8 | 쓰기·읽기 커넥션 모두 살아있음 | `curl -s https://app.<SHOP_DOMAIN>/readyz` |
| 9 | swap 이 실제로 잡힘 | `free -h` |
| 10 | 메모리 여유 | `docker stats --no-stream` |

```bash
# 6번을 헤더로 직접 확인
curl -sI https://api.<TRACK_DOMAIN>/diag | grep -i set-cookie

# 7번 — 쿠키 없이 5번 요청해 배정이 갈리는지
for i in $(seq 5); do
  curl -s https://lp.<SHOP_DOMAIN>/readyz | grep -o '"read_target":"[^"]*"'
done
```

---

## 8. 자주 막히는 곳

| 증상 | 원인 | 대응 |
|---|---|---|
| ACME 가 계속 실패 | DNS 전파 미완 | `dig` 로 네 호스트 확인 후 재시도 |
| ACME 챌린지가 404 | 80 포트 서버 블록이 안 떠 있음 | `docker compose logs openresty`. `/.well-known/acme-challenge/` 가 200 인지 |
| `too many certificates already issued` | 프로덕션 rate limit 소진 | 일주일 대기. **그래서 스테이징을 먼저 쓴다** |
| 루트 도메인에서 인증서 경고 | 서버 블록 누락 | 루트(`${SHOP_DOMAIN}`) 블록이 있어야 첫 443 블록으로 새지 않는다 |
| MySQL 컨테이너가 반복 재시작 | RAM 부족 | swap 확인. `docker compose logs mysql-primary` 에 OOM 흔적 |
| 복제가 안 붙음 | `REPL_PASSWORD` 불일치 | `.env` 를 바꿨다면 두 볼륨을 모두 지우고 다시 초기화해야 한다 |
| `.env` 값이 반영 안 됨 | compose 가 캐시된 설정 사용 | `docker compose up -d --force-recreate` |
| 502 Bad Gateway | `app` 컨테이너 미기동 | `docker compose ps`, `logs app` |
| 마이그레이션이 `(없음)` 에서 안 올라감 | `migration_version` 을 안 올렸다 | 새 마이그레이션을 추가하면 `config/migration.php` 의 목표 버전도 올린다 |
| 로그인이 간헐적으로 풀림 | 세션 조회가 복제본으로 갔다 | `database.php` 의 `$active_group` 이 `write` 인지 확인 |

---

## 9. 다음 단계

| 단계 | 할 일 |
|---|---|
| 다음 | 랜딩 파라미터 파싱 → 쿠키 발급, 브리지 302 |
| 그 다음 | CORS 격파, `sendBeacon` 비교, 서드파티 쿠키 차단 실험 |
| 그 다음 | 채널 어댑터 + 아웃박스 워커 (`docker compose --profile worker up -d --scale worker=4`) |
