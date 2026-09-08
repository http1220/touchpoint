# 인프라 구축 절차 (D1~D2)

목표: **광고주 측 호스트(루트 · `lp.` · `m.` · `app.`)가 자물쇠 표시로 열리는 진단 화면.** 여기까지가 D1~D2다.
그 다음이 CodeIgniter 3 기동과 스키마 마이그레이션이다.

추적 도메인(`api.`)은 등록되면 붙인다. 없어도 여기까지는 전부 진행된다 → [3-2](#3-2-추적-도메인이-아직-없을-때)

---

## 0. 준비물

| 항목 | 비고 |
|---|---|
| **등록 도메인 2개** | 서브도메인만 나누면 실험이 성립하지 않는다 → [ADR-002](decisions/ADR-002-two-registered-domains.md) · 등록 절차는 [domain-setup.md](domain-setup.md)<br>광고주 측 1개만 있어도 착수는 된다 (3-2) |
| AWS 계정 | 없으면 카드 등록·본인인증에 반나절. **결제 알림을 먼저 걸고 인스턴스를 만든다** (2-1) |
| DNS | 도메인을 Route 53에서 등록했다면 호스팅 영역이 이미 있다. 확인: `dig +short NS <도메인>` 에 **awsdns** 가 나오는지 (1장) |
| GA4 속성 | `measurement_id` + **API secret** (D12에 필요, 리드타임 대비 미리) |

> ⚠️ **TLD를 아끼지 않는다.** 저가 TLD는 광고 차단기·기업 DNS에서 통째로 차단되는 일이 있어, 이 프로젝트에서는 **TLD가 실험 변수가 된다.** 요청이 막혔을 때 `SameSite` 때문인지 TLD 때문인지 구분할 수 없게 된다 → [domain-setup.md](domain-setup.md) 2장

---

## 1. DNS

### 어디서 하는가

도메인을 **Route 53에서 등록했다면 Route 53**이 맞다. 등록과 동시에 호스팅 영역이 만들어지고 도메인의 네임서버가 거기를 가리킨다.

확인은 한 줄이면 된다.

```bash
dig +short NS sshwan.com
```

`ns-xxxx.awsdns-xx.co.uk` 같은 **awsdns** 이름 4개가 나오면 Route 53이 권한 있는 DNS다. 다른 등록기관 이름이 나오면 그쪽 DNS를 쓰고 있는 것이니, Route 53 호스팅 영역의 NS 4개를 등록기관에 그대로 넣어 위임하거나, 아예 등록기관 DNS에서 레코드를 만든다. **둘 중 하나만 한다** — 양쪽에 레코드를 만들어 두면 어느 쪽이 먹히는지 알 수 없게 된다.

> **호스팅 영역을 중복으로 만들지 않는다.** 같은 도메인으로 두 번째 영역을 만들면 NS가 달라 아무것도 안 먹고, 영역마다 **월 $0.50**이 나간다. Route 53 비용은 호스팅 영역 $0.50/월 + 쿼리 백만 건당 $0.40 이고, 이 프로젝트에서 쿼리 비용은 사실상 0이다.

### 레코드 4개

**EIP를 먼저 할당해 둔다** ([2장 탄력적 IP](#탄력적-ip)). 값이 없으면 레코드를 만들 수 없다. 인스턴스보다 EIP를 먼저 만들어도 되므로, DNS 전파를 기다리는 동안 인스턴스를 만드는 편이 시간이 덜 든다.

호스팅 영역 → **레코드 생성** → 라우팅 정책 **단순 라우팅** → **단순 레코드 정의**:

| 레코드 이름 | 레코드 유형 | 값 | 비고 |
|---|---|---|---|
| *(비움)* | A | `<EIP>` | 루트. 비워 두면 `sshwan.com` 자체 |
| `lp` | A | `<EIP>` | 랜딩·브리지 |
| `m` | A | `<EIP>` | 모바일 (UA 302 대상) |
| `app` | A | `<EIP>` | 서비스·전환 |
| `api` | A | `<EIP>` | 수집. **추적 도메인의** 호스팅 영역에 만든다 |

**트래픽 라우팅 대상**은 드롭다운에서 **"IP 주소 또는 다른 값에 대한 별칭이 아님"** 을 고르고 EIP를 붙여 넣는다.

> ### 왜 별칭(Alias)이 아닌가
>
> 드롭다운에 CloudFront·ALB·S3 같은 "별칭" 항목이 잔뜩 있는데, **EC2 인스턴스와 EIP는 별칭 대상이 아니다.** 별칭은 AWS가 IP를 대신 관리해 주는 리소스(ALB·CloudFront·S3 웹사이트·API Gateway·Global Accelerator)에만 붙는다. 우리는 고정 IP 하나를 직접 가리키므로 평범한 A 레코드다.
>
> 나중에 CDN을 붙이면 그때는 CloudFront 배포에 대한 별칭이 등장한다 → [ADR-015](decisions/ADR-015-image-pipeline-cdn.md)

**TTL은 300초.** 구축 중에는 60으로 낮춰 두면 오타를 고쳤을 때 5분을 기다리지 않아도 된다. 안정되면 300으로 올린다.

**NS와 SOA 레코드는 건드리지 않는다.** 자동 생성된 것이고, 지우면 도메인 전체가 죽는다.

CLI로 하려면:

```bash
ZONE=$(aws route53 list-hosted-zones-by-name --dns-name sshwan.com \
        --query 'HostedZones[0].Id' --output text)
EIP=<EIP>

for name in sshwan.com lp.sshwan.com m.sshwan.com app.sshwan.com; do
  aws route53 change-resource-record-sets --hosted-zone-id "$ZONE" \
    --change-batch "{\"Changes\":[{\"Action\":\"UPSERT\",\"ResourceRecordSet\":{
      \"Name\":\"$name\",\"Type\":\"A\",\"TTL\":300,
      \"ResourceRecords\":[{\"Value\":\"$EIP\"}]}}]}"
done
```

### 전파 확인

```bash
dig +short sshwan.com lp.sshwan.com m.sshwan.com app.sshwan.com
```

> **모든 줄이 같은 EIP를 뱉을 때까지 다음 단계로 넘어가지 않는다.** DNS가 안 된 상태로 certbot을 돌리면 ACME 검증이 실패하면서 rate limit만 소모한다.
>
> Route 53은 권한 있는 서버라 변경이 초 단위로 반영된다. 그런데도 안 보이면 캐시가 원인이므로 권한 있는 서버에 직접 물어본다.
>
> ```bash
> dig +short @ns-440.awsdns-55.com lp.sshwan.com    # NS 중 아무거나
> ```
>
> 여기서는 나오는데 `dig +short lp.sshwan.com` 에서 안 나오면 기다리면 된다. 여기서도 안 나오면 레코드가 잘못된 것이다.

> AAAA(IPv6)는 만들지 않는다. EC2에 IPv6를 주지 않았으므로 AAAA가 있으면 IPv6 우선 클라이언트가 먼저 실패한 뒤 IPv4로 넘어와 첫 요청이 느려진다.

---

## 2. EC2 — 인스턴스 생성

**t3.small (x86_64).** t3.micro(1GB)로는 MySQL 2대가 올라가지 않는다 → [ADR-007](decisions/ADR-007-read-write-split.md)

리전은 **ap-northeast-2 (서울)**. 지연이 짧고, 광고 매체 응답 시간을 잴 때 태평양 왕복이 섞이지 않는다.

### 시작 마법사 — 항목별로

| 항목 | 값 | 왜 |
|---|---|---|
| 이름 | `touchpoint` | 태그 `Project=touchpoint` 도 같이. 비용 할당 태그로 쓴다 |
| AMI | **Amazon Linux 2023**, x86_64 | `dnf` 로 docker 설치. arm64(t4g)는 더 싸지만 이미지 아키텍처를 매번 확인해야 한다 |
| 인스턴스 유형 | **t3.small** | vCPU 2 / RAM 2GB |
| 키 페어 | **새로 생성**, ED25519, `.pem` | 다운로드는 **한 번뿐**이다. 잃으면 인스턴스를 다시 만들어야 한다 |
| VPC / 서브넷 | 기본값 | 단일 AZ는 의도적 선택 |
| 퍼블릭 IP 자동 할당 | 활성화 | EIP 를 따로 붙일 것이므로 결국 대체된다 |
| 보안 그룹 | **새로 생성** (아래) | |
| 스토리지 | **30 GiB gp3**, 암호화 켜기 | 기본 8GiB로는 도커 이미지 + MySQL 데이터가 안 들어간다. gp3 는 3000 IOPS 가 기본 포함이라 추가 비용이 없다 |

### 보안 그룹

| 유형 | 포트 | 소스 | 비고 |
|---|---|---|---|
| SSH | 22 | **내 IP** | `0.0.0.0/0` 으로 열지 않는다. 공개 22번은 수 분 안에 스캔이 붙는다 |
| HTTP | 80 | `0.0.0.0/0` | **ACME 챌린지에 필요**하다. 막으면 인증서를 못 받는다 |
| HTTPS | 443 | `0.0.0.0/0` | |

3306(MySQL)은 **열지 않는다.** DB는 컨테이너 네트워크 안에서만 접근한다. 외부에서 봐야 하면 SSH 터널을 쓴다.

```bash
ssh -i touchpoint.pem -L 3307:127.0.0.1:3306 ec2-user@<EIP>
```

### 고급 세부 정보 — 여기 하나가 비용의 핵심이다

| 항목 | 값 | 왜 |
|---|---|---|
| **크레딧 사양** | **`standard`** ← **반드시** | 아래 |
| 구매 옵션 | 켜지 않음(온디맨드) | 스팟은 싸지만 예고 없이 회수된다. 면접 중에 서버가 사라지면 안 된다 |
| 종료 동작 | `중지` | |
| 세부 CloudWatch 모니터링 | **끔** | 1분 단위 지표는 유료다. 5분 기본 지표로 충분하다 |
| 종료 방지 | 끔 | 끝나면 지워야 하므로 오히려 방해가 된다 |
| IMDS 버전 | `V2만 필요` (기본) | |

> ### `standard` vs `unlimited` — t3에서 요금이 새는 유일한 구멍
>
> t3 계열은 CPU 크레딧으로 동작한다. 기본값인 **`unlimited`** 모드는 크레딧이 떨어져도 계속 버스트하고, 초과분을 **vCPU-시간당 별도 과금**한다. 부하 테스트를 돌리거나 뭔가 폭주하면 인스턴스 요금과 무관하게 청구가 붙는 경로가 여기다.
>
> **`standard`** 로 두면 크레딧이 떨어졌을 때 **느려질 뿐 추가 과금이 없다.** 이 프로젝트에서 CPU가 오래 100%일 이유가 없고, 있다면 그건 고쳐야 할 버그이지 돈으로 덮을 일이 아니다.
>
> 이미 만들었다면: EC2 → 인스턴스 선택 → 작업 → 인스턴스 설정 → **CPU 크레딧 사양 수정** → `standard`. 실행 중에도 바꿀 수 있다.

### 사용자 데이터 (선택)

시작 시 swap과 docker를 한 번에 넣는다. 나중에 손으로 해도 되지만, 이 스크립트가 있으면 인스턴스를 다시 만들 때 같은 상태가 재현된다.

```bash
#!/bin/bash
set -eux
# swap 2GB — RAM 2GB에 MySQL 2대 + PHP-FPM + OpenResty 다.
# 컨테이너 상한 합계가 1.2GB라 여유가 크지 않다.
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
# swap을 아껴 쓰게 한다. 기본값 60은 RAM이 남는데도 스왑아웃한다.
sysctl -w vm.swappiness=10
echo 'vm.swappiness=10' > /etc/sysctl.d/99-swap.conf

dnf install -y docker git
systemctl enable --now docker
usermod -aG docker ec2-user

# Compose v2 는 AL2023 의 docker 패키지에 들어 있지 않다.
# dnf 에도 없으므로 공식 릴리스를 CLI 플러그인 디렉터리에 직접 넣는다.
# 버전을 박아 두지 않고 최신 태그를 받아온다 — 존재하지 않는 버전을
# 적어 두면 몇 달 뒤 이 스크립트가 조용히 실패한다.
COMPOSE_VER=$(curl -sI https://github.com/docker/compose/releases/latest \
  | tr -d "\r" | awk 'tolower($0) ~ /^location:/ {n=split($0,a,"/"); print a[n]}')
install -d /usr/libexec/docker/cli-plugins
# t4g(arm64) 라면 파일명이 docker-compose-linux-aarch64 다.
curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VER}/docker-compose-linux-x86_64" \
  -o /usr/libexec/docker/cli-plugins/docker-compose
chmod +x /usr/libexec/docker/cli-plugins/docker-compose
```

### 탄력적 IP

EC2 → 탄력적 IP → 할당 → 인스턴스에 연결.

인스턴스를 중지·시작하면 퍼블릭 IP가 바뀐다. DNS를 다시 고치는 것보다 EIP 하나 붙이는 게 낫다.

> **주의**: 2024-02-01부터 **모든 퍼블릭 IPv4 주소는 사용 중이어도 시간당 요금**이 붙는다(월 $3~4). 그리고 **연결되지 않은 EIP는 더 비싸다.** 프로젝트가 끝나면 인스턴스 종료와 **EIP 해제**를 같이 해야 한다 — 안 쓰는 EIP를 남겨두는 것이 이 프로젝트에서 가장 흔한 잔여 청구 경로다.

### 확인

```bash
TOKEN=$(curl -sX PUT http://169.254.169.254/latest/api/token -H "X-aws-ec2-metadata-token-ttl-seconds: 60"); curl -s -H "X-aws-ec2-metadata-token: $TOKEN" http://169.254.169.254/latest/meta-data/instance-type
```

`t3.small` 이 나와야 한다. 이어서:

```bash
nproc      # 2
free -h    # Mem 1.9Gi, Swap 2.0Gi
docker compose version
```

`free -h` 가 `949Mi` 를 뱉으면 아직 t3.micro다.

---

## 2-1. 비용이 새지 않게 하는 장치

**AWS에는 "이 금액을 넘으면 자동으로 끄기"가 없다.** 하드 캡은 존재하지 않으므로, 대신 **요금이 붙을 수 있는 경로를 미리 없애고 + 알림을 건다.**

### ① 이 구성에서 과금되는 것 전부

| 항목 | 대략 (서울, 월) | 비고 |
|---|---|---|
| t3.small 온디맨드 | $18~19 | `standard` 크레딧이면 이게 상한 |
| gp3 30GiB | $2~3 | |
| 퍼블릭 IPv4 1개 | $3~4 | 2024-02 이후 |
| Route 53 호스팅 영역 | $0.50 | 영역당. **중복 생성하면 그만큼 더 나간다** |
| Route 53 쿼리 | ~$0 | 백만 건당 $0.40 |
| 데이터 전송(아웃바운드) | ~$0 | 월 100GB 무료. 이 프로젝트는 그 근처도 안 간다 |
| **합계** | **월 $25 안팎 / 2주 $13 안팎** | |

목록에 없는 것이 붙으면 그건 실수다. **NAT 게이트웨이($40/월), ALB($20/월), RDS, EKS** — 이 넷이 개인 프로젝트 청구서를 터뜨리는 단골이고, 이 구성에는 하나도 없다.

### ② 예산 알림 (무료, 2개까지)

Billing → **예산** → 예산 생성 → 월별 비용 예산 `$15`
- 알림: 실제 비용 **50% / 80% / 100%**, 그리고 **예측 비용 100%**
- 예측 알림이 실질적으로 중요하다. 실제 100%에 도달했을 땐 이미 늦었고, 예측은 며칠 전에 울린다

### ③ 비용 이상 탐지 (무료)

Billing → **비용 이상 탐지** → 모니터 생성(서비스별) → 알림 구독 `$5` 이상 편차.
평소 패턴에서 벗어나면 알려준다. 예산 알림이 못 잡는 "갑자기 새 서비스가 켜진" 경우를 여기서 잡는다.

### ④ 프리티어 사용량 알림

Billing → 결제 기본 설정 → **프리티어 사용량 알림 받기** 체크 + 이메일.

> 2025-07-15 이후 만든 계정은 750시간 프리티어가 아니라 **6개월간 $200 크레딧** 방식이다. 크레딧 잔액은 Billing → 크레딧에서 본다. t3.small 2주는 이 안에서 처리된다.

### ⑤ 루트 계정 보호

MFA를 켜고, 일상 작업은 IAM 사용자로 한다. **비용이 폭발하는 시나리오 1위는 과금 실수가 아니라 액세스 키 유출**이다. 저장소에 `.env`가 커밋되지 않는 것과 같은 이유다.

### ⑥ 끝나면 지우는 목록

면접이 끝나면 이 순서로 지운다. **하나라도 빠지면 청구가 계속된다.**

```
1. EC2 인스턴스 종료(terminate)      — 중지가 아니라 종료
2. 탄력적 IP 해제(release)           — 가장 흔히 빠뜨리는 것
3. EBS 볼륨 확인                     — "종료 시 삭제"가 켜져 있으면 자동
4. EBS 스냅샷 / AMI                  — 만든 적 있으면
5. CloudWatch 로그 그룹              — 만든 적 있으면
```

도메인은 1년치를 이미 결제했으므로 그대로 둔다. 서버만 내린다.

---
## 3. 저장소와 환경 설정

**여기부터는 전부 EC2 인스턴스 안에서 한다.** 로컬 PC 가 아니다.

```bash
ssh -i touchpoint.pem ec2-user@<EIP>
```

> `Permissions 0644 for 'touchpoint.pem' are too open` 이 나오면 키 파일 권한 문제다. 리눅스·맥은 `chmod 400 touchpoint.pem`. 윈도우 PowerShell 이면 파일 속성 → 보안 → 고급에서 상속을 끊고 본인만 남긴다.

### 3-0. 사용자 데이터가 제대로 돌았는지

```bash
free -h                 # Swap 2.0Gi
docker compose version  # v2.x
groups                  # docker 가 보여야 한다
```

`groups` 에 `docker` 가 없으면 **한 번 로그아웃했다 다시 들어온다.** `usermod -aG docker` 는 새 로그인 세션부터 적용된다. 그래도 없으면 사용자 데이터가 실패한 것이니 [2장의 스크립트](#사용자-데이터-선택)를 `sudo` 붙여 손으로 돌린다.

### 3-0-1. `docker: 'compose' is not a docker command`

**Compose v2 는 AL2023 의 `docker` 패키지에 들어 있지 않다.** `dnf` 에도 별도 패키지가 없어서, 공식 릴리스 바이너리를 CLI 플러그인 디렉터리에 직접 넣어야 한다. 사용자 데이터에 이미 넣어 뒀지만, 그 전에 만든 인스턴스라면 지금 한 번 돌린다.

```bash
COMPOSE_VER=$(curl -sI https://github.com/docker/compose/releases/latest \
  | tr -d "\r" | awk 'tolower($0) ~ /^location:/ {n=split($0,a,"/"); print a[n]}')
echo "$COMPOSE_VER"                      # v로 시작하는 태그가 찍히는지 먼저 본다

sudo install -d /usr/libexec/docker/cli-plugins
sudo curl -fsSL \
  "https://github.com/docker/compose/releases/download/${COMPOSE_VER}/docker-compose-linux-x86_64" \
  -o /usr/libexec/docker/cli-plugins/docker-compose
sudo chmod +x /usr/libexec/docker/cli-plugins/docker-compose

docker compose version
```

> 버전을 문서에 박아 두지 않는 이유: 존재하지 않는 태그를 적어 두면 몇 달 뒤 이 절차가 404 로 조용히 실패한다. 최신 릴리스 태그를 리다이렉트에서 받아온다.
>
> `t4g`(arm64) 인스턴스라면 파일명이 `docker-compose-linux-aarch64` 다. `uname -m` 으로 확인한다.


### 3-1. 클론과 `.env`

```bash
git clone https://github.com/http1220/touchpoint.git
cd touchpoint
cp .env.example .env
chmod 600 .env          # 비밀값이 들어간다
openssl rand -hex 16    # 세 번 돌려서 아래 셋에 쓴다
```

`.env`에서 반드시 채울 것:

| 키 | 값 |
|---|---|
| `SHOP_DOMAIN` | `sshwan.com` |
| `TRACK_DOMAIN` | **비워 둔다** — 추적 도메인 등록 전 (아래 3-2) |
| `ACME_EMAIL` | 인증서 만료 알림 수신 주소 |
| `ACME_STAGING` | **처음에는 `true`** |
| `MYSQL_ROOT_PASSWORD` / `MYSQL_PASSWORD` | `openssl rand -hex 16` |
| `REPL_PASSWORD` | 복제 계정. **지금 정하고 바꾸지 않는다** — 바꾸려면 MySQL 볼륨 두 개를 지우고 처음부터 다시 초기화해야 한다 |
| `ENCRYPTION_KEY` | CI3 세션·해시 소금. 32자 hex |
| `CI_ENVIRONMENT` | 구축 중에는 `development`. **공개 전에 반드시 `production`** (아래) |

> ### `CI_ENVIRONMENT` 를 언제 바꾸는가
>
> `development` 는 PHP 에러를 **화면에 그대로 출력한다.** 파일 경로와 스택 트레이스가 방문자에게 보인다는 뜻이다. 구축 중에는 그게 있어야 원인을 빨리 찾지만, 링크를 남에게 주기 전에는 반드시 `production` 으로 바꾸고 컨테이너를 재기동한다.
>
> ```bash
> sed -i 's/^CI_ENVIRONMENT=.*/CI_ENVIRONMENT=production/' .env
> docker compose up -d --force-recreate app
> ```

### 3-2. 추적 도메인이 아직 없을 때

`TRACK_DOMAIN`을 비워 두면 `api.` 수집 호스트 없이 뜬다. 광고주 측(`lp.` `m.` `app.`)은 전부 정상 동작한다.

**대신 이 상태에서 성립하지 않는 것을 분명히 해 둔다.**

| | 한 도메인 | 두 도메인 |
|---|---|---|
| CI3·마이그레이션·세션 | ✅ | ✅ |
| 브리지 302·랜딩·파라미터 파싱 | ✅ | ✅ |
| 결제·코인 원장·전환 기록 | ✅ | ✅ |
| 아웃박스 워커·매체 전송(GA4/Meta) | ✅ | ✅ |
| 읽기 복제본 배정·복제 지연 | ✅ | ✅ |
| **CORS preflight · `SameSite=None`** | ❌ | ✅ |
| **서드파티 쿠키 차단 · CHIPS** | ❌ | ✅ |
| **`track.js` 서드파티 서빙** | ❌ | ✅ |

서브도메인으로 대체하지 않는다. 브라우저는 same-site를 **eTLD+1**로 판정하므로 `api.sshwan.com`은 `lp.sshwan.com`과 같은 사이트이고, 거기서는 차단도 `SameSite=None`도 재현되지 않는다 → [ADR-002](decisions/ADR-002-two-registered-domains.md)

도메인을 등록한 뒤 붙이는 절차는 세 줄이다.

```bash
# 1) .env 의 TRACK_DOMAIN 을 채운다
# 2) DNS A 레코드 api.<TRACK_DOMAIN> → 같은 EIP, 전파 확인
# 3) 인증서 발급 후 엣지 재기동
docker compose --profile cert run --rm certbot
docker compose up -d --force-recreate openresty
```

---
## 4. ACME 스테이징으로 먼저 검증

**이 단계를 건너뛰지 않는다.** Let's Encrypt 프로덕션은 도메인당 **주 5회** 제한이 있고, 설정 시행착오로 소모하면 일주일을 날린다.

인증서 발급은 certbot(webroot)으로 한다. `.env` 의 `ACME_STAGING` 이 비어 있지 않으면 `--staging` 이 붙는다.

```bash
# .env 에 ACME_STAGING=true 인지 확인
docker compose up -d openresty          # 80 포트로 ACME 챌린지를 받는다
```

> 이 명령이 `app` 컨테이너 이미지를 처음 빌드한다. PHP 확장을 컴파일하므로 **5~10분** 걸린다. 인증서만 받을 것이라 앱이 아직 비어 있어도(=`vendor/` 없음) 상관없다 — ACME 챌린지는 nginx 가 정적 파일로 응답한다.

```bash
docker compose --profile cert run --rm certbot
```

`SHOP_DOMAIN`(루트 + `lp.` `m.` `app.`)에 하나. `TRACK_DOMAIN` 이 채워져 있으면 `api.` 에 하나 더 발급된다. 비어 있으면 건너뛴다는 메시지가 나오고 정상 종료한다.

```bash
curl -kI https://lp.<SHOP_DOMAIN> 2>&1 | head -1
# 여기서 확인하는 것은 TLS 핸드셰이크가 되느냐다.
# 502 가 나와도 정상이다 — 아직 composer install 전이라 앱이 비어 있다(6-1).
# 인증서 경고(-k)도 스테이징 CA라 정상이다.
```

> 발급 실패 시 `openresty` 컨테이너 로그에서 `/.well-known/acme-challenge/` 요청이 **200 으로** 찍혔는지 본다. 404 라면 DNS 나 webroot 볼륨 문제다.

---

## 5. 프로덕션 인증서로 전환

스테이징이 모든 호스트에 성공했으면:

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

### 6-1. 의존성 — 이걸 빼먹으면 전부 500 이다

`vendor/` 는 저장소에 없다(`.gitignore`). CodeIgniter 자체가 composer 의존성이므로, **클론 직후에는 프레임워크가 아예 없는 상태다.**

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" \
  app composer install --no-dev --optimize-autoloader
```

| 옵션 | 왜 |
|---|---|
| `--no-deps` | MySQL 을 띄우지 않는다. 의존성 설치에 DB 가 필요 없다 |
| `--user $(id -u):$(id -g)` | 없으면 `vendor/` 가 root 소유로 생겨 나중에 손댈 때 sudo 가 필요해진다 |
| `--no-dev` | PHPUnit 은 서버에 필요 없다. 테스트는 CI 가 돌린다 |
| `--optimize-autoloader` | 클래스맵을 미리 만든다. 요청마다 파일을 찾지 않는다 |

`ls vendor/codeigniter/framework/system` 이 나오면 성공이다.

### 6-2. 기동

```bash
docker compose up -d --build
docker compose ps        # openresty · app · mysql-primary · mysql-replica 넷이 Up
```

> 첫 `--build` 는 PHP 확장(`intl`·`mysqli`·`opcache`…)을 컴파일하므로 t3.small 에서 **5~10분** 걸린다. 두 번째부터는 캐시된다. 여기서 멈춘 것처럼 보여도 기다린다.

### 6-3. 마이그레이션

```bash
docker compose exec app php public/index.php cli/migrate latest
docker compose exec app php public/index.php cli/migrate current   # 20260909000700
```

테이블 18개 + `ci_migrations` 가 생긴다.

```bash
docker compose exec mysql-primary sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE" -e "SHOW TABLES"'
```

### 6-4. 복제 확인

**둘 다 `Yes` 가 아니면 읽기 분리 실험 전체가 성립하지 않는다.** MySQL 8.0.22 부터 항목 이름이 `Slave_*` 에서 `Replica_*` 로 바뀌었다.

```bash
docker compose exec mysql-replica sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW REPLICA STATUS\G"' \
  | grep -E "Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_Error"
```

붙지 않았다면 원인은 십중팔구 `REPL_PASSWORD` 불일치다. `.env` 를 나중에 고쳤다면 볼륨을 지우고 처음부터 초기화해야 한다.

```bash
docker compose down
docker volume rm touchpoint_mysql_primary touchpoint_mysql_replica
docker compose up -d
```

### 6-5. 로그 보기

앱 로그는 파일이 아니라 **컨테이너 stdout/stderr** 로 나온다. 엣지와 앱을 한 화면에서 볼 수 있다.

```bash
docker compose logs -f openresty app
```

앱 로그는 JSON 한 줄이고 `trace_id` 가 붙어 있다. 엣지 로그의 `trace_id` 와 같은 값이므로, 느린 요청 하나를 잡아 양쪽을 이어서 볼 수 있다.

```bash
docker compose logs app | grep '"trace_id":"<그 값>"'
```

---
## 7. 검증 — 여기까지가 D1~D2 완료 조건

| # | 확인 | 방법 |
|---|---|---|
| 1 | 호스트가 **자물쇠 표시**로 열림 | 루트 · `lp.` · `m.` · `app.` (추적 도메인이 있으면 `api.` 까지) |
| 2 | 진단 페이지의 **역할 판별**이 맞음 | `/diag` 에서 `lp` / `m` / `app` / `api` 로 표시되는지 |
| 3 | 인증서가 도메인별로 발급됨 | `curl -vI https://... 2>&1 \| grep -i "issuer\|subject"` |
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
| ACME 가 계속 실패 | DNS 전파 미완 | `dig` 로 모든 호스트 확인 후 재시도 |
| ACME 챌린지가 404 | 80 포트 서버 블록이 안 떠 있음 | `docker compose logs openresty`. `/.well-known/acme-challenge/` 가 200 인지 |
| `too many certificates already issued` | 프로덕션 rate limit 소진 | 일주일 대기. **그래서 스테이징을 먼저 쓴다** |
| 루트 도메인에서 인증서 경고 | 서버 블록 누락 | 루트(`${SHOP_DOMAIN}`) 블록이 있어야 첫 443 블록으로 새지 않는다 |
| MySQL 컨테이너가 반복 재시작 | RAM 부족 | swap 확인. `docker compose logs mysql-primary` 에 OOM 흔적 |
| 복제가 안 붙음 | `REPL_PASSWORD` 불일치 | `.env` 를 바꿨다면 두 볼륨을 모두 지우고 다시 초기화해야 한다 |
| `.env` 값이 반영 안 됨 | compose 가 캐시된 설정 사용 | `docker compose up -d --force-recreate` |
| `docker: 'compose' is not a docker command` | AL2023 의 docker 패키지에 Compose v2 가 없다 | 3-0-1 |
| 모든 요청이 500 | **`composer install` 을 안 했다.** `vendor/` 가 없으면 CodeIgniter 자체가 없다 | 6-1 |
| `Class "CI_Controller" not found` | 위와 같다 | 6-1 |
| `vendor/` 를 지울 수 없다(Permission denied) | `--user` 없이 composer 를 돌려 root 소유로 생겼다 | `sudo rm -rf vendor` 후 6-1 을 다시 |
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
