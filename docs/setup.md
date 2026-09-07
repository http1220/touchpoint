# 인프라 구축 절차 (D1~D2)

목표: **호스트 3개가 자물쇠 표시로 열리는 빈 화면.** 여기까지가 D1~D2다.
CI4는 D3부터 올린다.

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

> **세 줄이 모두 같은 EIP를 뱉을 때까지 다음 단계로 넘어가지 않는다.** DNS가 안 된 상태로 Caddy를 올리면 ACME가 실패하면서 rate limit만 소모한다.

---

## 2. EC2

t3.micro(프리티어). 보안 그룹은 **22 / 80 / 443**만 연다.

### swap 2GB — 건너뛰면 MySQL이 죽는다

RAM 1GB에 MySQL + PHP-FPM + Caddy를 올린다. swap 없이는 OOM이 난다.

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
git clone <repo> attribution-bridge
cd attribution-bridge
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

`Caddyfile` 전역 블록의 `acme_ca` 줄 주석을 해제한다.

```caddyfile
acme_ca https://acme-staging-v02.api.letsencrypt.org/directory
```

```bash
docker compose up -d
docker compose logs -f caddy
```

로그에 `certificate obtained successfully`가 세 호스트 모두에 대해 뜨는지 본다.

```bash
curl -kI https://lp.<SHOP_DOMAIN>
# 200 이 오면 성공. 인증서 경고(-k)는 스테이징 CA라 정상이다.
```

---

## 5. 프로덕션 인증서로 전환

스테이징이 세 호스트 모두 성공했으면:

1. `Caddyfile`의 `acme_ca` 줄을 **다시 주석 처리**
2. `.env`의 `ACME_STAGING=false`
3. 스테이징 인증서를 비우고 재발급

```bash
docker compose down
docker volume rm attribution-bridge_caddy_data
docker compose up -d
docker compose logs -f caddy
```

---

## 6. 검증 — 여기까지가 D1~D2 완료 조건

| # | 확인 | 방법 |
|---|---|---|
| 1 | 세 호스트가 **자물쇠 표시**로 열림 | 브라우저로 각각 접속 |
| 2 | 진단 페이지의 **역할 판별**이 맞음 | `lp` / `app` / `api` 로 표시되는지 |
| 3 | 인증서가 두 도메인에 각각 발급됨 | `curl -vI https://... 2>&1 \| grep -i "issuer\|subject"` |
| 4 | HTTP/2 또는 3으로 응답 | 진단 페이지의 `HTTP 버전` 행 |
| 5 | **쿠키 속성이 정책표와 일치** | DevTools → Application → Cookies 에서 `ab_probe_*` 확인 |
| 6 | `api.` 쿠키에 `Partitioned`가 붙음 | 위와 동일. `SameSite=None; Secure`와 함께여야 효력 |
| 7 | swap이 실제로 잡힘 | `free -h` |
| 8 | 메모리 여유 | `docker stats --no-stream` |

```bash
# 6번을 헤더로 직접 확인
curl -sI https://api.<TRACK_DOMAIN>/ | grep -i set-cookie
```

---

## 7. 자주 막히는 곳

| 증상 | 원인 | 대응 |
|---|---|---|
| ACME가 계속 실패 | DNS 전파 미완 | `dig`로 세 호스트 확인 후 재시도 |
| `too many certificates already issued` | 프로덕션 rate limit 소진 | 일주일 대기. **그래서 스테이징을 먼저 쓴다** |
| MySQL 컨테이너가 반복 재시작 | RAM 부족 | swap 확인. `docker compose logs mysql`에 OOM 흔적 |
| `.env` 값이 반영 안 됨 | compose가 캐시된 설정 사용 | `docker compose up -d --force-recreate` |
| 502 Bad Gateway | `app` 컨테이너 미기동 | `docker compose ps`, `logs app` |
| worker가 크래시 루프 | CI4·`dispatch:work`가 아직 없음 | 정상이다. worker는 `--profile worker`로만 뜬다 |

---

## 8. 다음 단계

| 일차 | 할 일 |
|---|---|
| D3~D5 | CI4 설치, 마이그레이션, 랜딩 파라미터 파싱 → 쿠키 발급 |
| D6~D7 | 브리지 302 리다이렉트, 파라미터 전달 2방식 비교 |
| D8~D11 | CORS 격파, `sendBeacon` 비교, 서드파티 쿠키 차단 실험 |
| D12~ | 아웃박스 워커 (`docker compose --profile worker up -d`) |
