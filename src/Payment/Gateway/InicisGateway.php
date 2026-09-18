<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Channel\HttpClient;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * KG이니시스 PC 웹표준 결제 · INIAPI 환불. **카드만** → plan-multi-pg.md D4
 *
 * 흐름(원문 STEP1~4 · 망취소):
 *
 *   ① checkout   결제창 필드에 서명한다 (signature · verification · mKey)
 *   ② 브라우저   이니시스 결제창 → 우리 returnUrl 로 **POST** (authToken · authUrl · netCancelUrl · idc_name)
 *   ③ confirm    authUrl 을 검사하고 승인을 요청한다. 응답의 금액·주문번호를 우리 행과 대조한다
 *   ④ 장부       호출자가 applyEvent(captured). 실패하면 compensate() = 망취소
 *
 * ── 이 클래스가 스스로 지키는 선 ──
 *
 *   - **검사를 통과하지 못한 URL 로는 요청하지 않는다**(C1). 거절은 HTTP 0회다
 *   - **되돌릴 수 없는 승인은 요청하지 않는다.** 망취소 URL 이 허용 목록 밖이면
 *     승인도 하지 않는다 — 승인 뒤 장부에 실패했을 때 되돌릴 길이 없기 때문이다
 *   - 응답을 못 받으면 failed 가 아니라 **unknown** 이다(B4)
 *   - 금액이 다르면 승인이 났어도 받지 않고 되돌린다
 *
 * 모드: test 는 스테이징 호스트만(원문 "테스트MID 만 사용가능"), live 는 운영 호스트만.
 */
final class InicisGateway implements GatewayInterface
{
    public const NAME = 'inicis';

    /**
     * 승인 결과에서 남길 필드 → PayloadRedactor.
     *
     * 뺀 것: `CARD_Num`(카드번호) · `buyerName` `buyerTel` `buyerEmail` `custEmail`
     * (구매자 정보 — 원문상 승인 결과로 되돌아온다) · `P_FN_NM` 등 표시용 값.
     */
    private const STORED_FIELDS = [
        'resultCode', 'resultMsg', 'tid', 'mid', 'MOID', 'TotPrice', 'payMethod',
        'applDate', 'applTime', 'applNum', 'CARD_Code', 'CARD_Quota', 'CARD_Interest', 'EventCode',
    ];

    /** 인증 단계에서 실패해 돌아왔을 때 남길 것 */
    private const AUTH_FAILED_FIELDS = ['resultCode', 'resultMsg', 'orderNumber', 'idc_name'];

    private const REFUND_FIELDS = ['resultCode', 'resultMsg', 'cancelDate', 'cancelTime', 'detailResultCode'];

    /** 원문: 승인 성공 "0000", INIAPI 성공 "00" — **자릿수가 다르다** */
    private const APPROVED = '0000';
    private const REFUND_OK = '00';

    private readonly Closure $clockMs;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $mode,
        private readonly string $mid,
        private readonly string $signKey,
        /** INIAPI key. 비면 환불을 하지 않는다 */
        private readonly string $iniapiKey = '',
        /** 원문 "가맹점 요청 서버IP". 해시 대상이라 설정값으로 받는다 */
        private readonly string $clientIp = '',
        private readonly int $timeoutMs = 10000,
        ?Closure $clockMs = null,
    ) {
        if (!InicisEndpoints::isMode($mode)) {
            throw new InvalidArgumentException('이니시스 모드는 test | live 입니다: '.$mode);
        }

        // 시각은 주입받는다. 서명에 들어가서 고정하지 않으면 테스트할 수 없다.
        $this->clockMs = $clockMs ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * KRW 만. 원문상 USD 카드 결제도 되지만 해외는 페이팔로 보내기로 했다
     * → plan-multi-pg.md 6장 B1.
     */
    public function supports(string $currency): bool
    {
        return strtoupper($currency) === 'KRW';
    }

    public function checkout(Checkout $c): array
    {
        if (!$this->supports($c->currency)) {
            throw new InvalidArgumentException('이니시스는 KRW 만 받습니다: '.$c->currency);
        }

        $ts = ($this->clockMs)();
        $price = $c->amountMinor;   // KRW 는 소수 0자리 — minor unit 이 곧 원이다

        return [
            'action' => InicisEndpoints::js($this->mode),
            'fields' => [
                'version' => '1.0',
                'gopaymethod' => 'Card',
                'mid' => $this->mid,
                'oid' => $c->paymentUid,
                'price' => (string) $price,
                'timestamp' => (string) $ts,
                'use_chkfake' => 'Y',
                'signature' => InicisHash::signature($c->paymentUid, $price, $ts),
                'verification' => InicisHash::verification($c->paymentUid, $price, $this->signKey, $ts),
                'mKey' => InicisHash::mKey($this->signKey),

                // ISO 4217 이 아니다. 원문: ["WON":한화,"USD":달러]
                'currency' => 'WON',

                'goodname' => $c->goodName,
                'buyername' => $c->buyerName,
                'buyertel' => $c->buyerTel,
                'buyeremail' => $c->buyerEmail,
                'returnUrl' => $c->returnUrl,
                'closeUrl' => $c->closeUrl,

                // **없으면 idc_name 이 오지 않는다** — 원문 "IDC센터코드 수신 사용옵션 세팅 필수".
                // idc_name 이 없으면 authUrl 을 검사할 기준이 없다.
                'acceptmethod' => 'centerCd(Y)',
                'charset' => 'UTF-8',
            ],
        ];
    }

    public function confirm(array $input, string $paymentUid, int $amountMinor, string $currency): GatewayResult
    {
        $code = self::str($input['resultCode'] ?? null);

        if ($code !== self::APPROVED) {
            // 인증 단계에서 끝났다. 승인 요청 전이라 PG 에 남은 승인이 없다.
            // 이 코드도 **브라우저가 가져온 값**이라 장부를 바꾸지 않는다(rejected).
            // 결제는 created 로 남고, 오래된 결제 보고가 잡는다 → D3
            return GatewayResult::rejected(
                'auth-failed: '.$code.' '.self::str($input['resultMsg'] ?? null),
                PayloadRedactor::keep($input, self::AUTH_FAILED_FIELDS, (string) json_encode($input))
            );
        }

        // ── 여기서부터의 거절은 전부 HTTP 0회이고, 장부를 바꾸지 않는다(rejected) ──
        // 근거가 브라우저 입력이라, 이걸로 failed 를 적으면 위조 요청 하나로 남의 결제를 실패시킬 수 있다.

        if (self::str($input['mid'] ?? null) !== $this->mid) {
            return GatewayResult::rejected('mid-mismatch');
        }

        if (self::str($input['orderNumber'] ?? null) !== $paymentUid) {
            return GatewayResult::rejected('order-mismatch');
        }

        if (!$this->supports($currency)) {
            return GatewayResult::rejected('currency-unsupported: '.$currency);
        }

        $token = self::str($input['authToken'] ?? null);
        $idc = self::str($input['idc_name'] ?? null);
        $authUrl = self::str($input['authUrl'] ?? null);
        $netCancelUrl = self::str($input['netCancelUrl'] ?? null);

        if ($token === '') {
            return GatewayResult::rejected('no-auth-token');
        }

        if (!InicisEndpoints::isApprovalUrl($authUrl, $this->mode, $idc)) {
            // 값 전체를 남기지 않는다. 호스트만 — 공격 시도의 모양을 보기엔 충분하다.
            return GatewayResult::rejected(sprintf(
                'untrusted-auth-url: mode=%s idc=%s host=%s',
                $this->mode, $idc, (string) parse_url($authUrl, PHP_URL_HOST)
            ));
        }

        if (!InicisEndpoints::isNetCancelUrl($netCancelUrl, $this->mode)) {
            // 되돌릴 수 없는 승인은 요청하지 않는다 — 클래스 주석
            return GatewayResult::rejected(sprintf(
                'untrusted-net-cancel-url: host=%s', (string) parse_url($netCancelUrl, PHP_URL_HOST)
            ));
        }

        $compensation = ['netCancelUrl' => $netCancelUrl, 'authToken' => $token];

        $res = $this->http->postForm($authUrl, $this->authFields($token, $amountMinor), $this->timeoutMs);

        if ($res->status === null) {
            // 요청이 닿았는지조차 모른다. PG 에 승인이 났을 수 있다.
            return GatewayResult::unknown('approval-no-response: '.(string) $res->error, $compensation);
        }

        $body = $res->json();

        if (!$res->isSuccess() || $body === []) {
            return GatewayResult::unknown('approval-unreadable: http '.$res->status, $compensation);
        }

        $stored = PayloadRedactor::keep($body, self::STORED_FIELDS, $res->body);
        $approved = self::str($body['resultCode'] ?? null);

        if ($approved !== self::APPROVED) {
            // PG 가 승인을 거절했다. 되돌릴 승인이 없다.
            return GatewayResult::failed('approval-failed: '.$approved.' '.self::str($body['resultMsg'] ?? null), $stored);
        }

        $mismatch = $this->mismatch($body, $paymentUid, $amountMinor);

        if ($mismatch !== null) {
            // 승인은 났는데 우리 행과 다르다. 받지 않고 되돌린다.
            return GatewayResult::failed('approval-mismatch: '.$mismatch, $stored, $compensation);
        }

        return GatewayResult::captured(['tid' => self::str($body['tid'])], $stored, $compensation);
    }

    public function compensate(GatewayResult $confirmed, int $amountMinor): bool
    {
        $url = $confirmed->compensation['netCancelUrl'] ?? '';
        $token = $confirmed->compensation['authToken'] ?? '';

        // confirm 에서 이미 검사했지만 다시 본다. 이 메서드만 따로 불리는 날을 막는다.
        if ($url === '' || $token === '' || !InicisEndpoints::isNetCancelUrl($url, $this->mode)) {
            return false;
        }

        $res = $this->http->postForm($url, $this->authFields($token, $amountMinor), $this->timeoutMs);

        return $res->isSuccess() && self::str($res->json()['resultCode'] ?? null) === self::APPROVED;
    }

    public function refund(array $refs, string $reason): GatewayResult
    {
        $tid = $refs['tid'] ?? '';

        if ($tid === '') {
            return GatewayResult::failed('no-tid');
        }

        if ($this->iniapiKey === '' || $this->clientIp === '') {
            return GatewayResult::failed('iniapi-not-configured');
        }

        $type = 'Refund';
        $paymethod = 'Card';   // 카드만 → D4. 수단이 늘면 승인 결과의 payMethod 에서 고른다
        $timestamp = $this->kstTimestamp();

        $res = $this->http->postForm(InicisEndpoints::refundUrl($this->mode), [
            'type' => $type,
            'paymethod' => $paymethod,
            'timestamp' => $timestamp,
            'clientIp' => $this->clientIp,
            'mid' => $this->mid,
            'tid' => $tid,
            'msg' => mb_strcut($reason, 0, 80, 'UTF-8'),   // 원문 80 byte
            'hashData' => InicisHash::refund($this->iniapiKey, $type, $paymethod, $timestamp, $this->clientIp, $this->mid, $tid),
        ], $this->timeoutMs);

        if ($res->status === null) {
            return GatewayResult::unknown('refund-no-response: '.(string) $res->error);
        }

        $body = $res->json();
        $stored = PayloadRedactor::keep($body, self::REFUND_FIELDS, $res->body);
        $code = self::str($body['resultCode'] ?? null);

        if ($code !== self::REFUND_OK) {
            return GatewayResult::failed('refund-failed: '.$code.' '.self::str($body['resultMsg'] ?? null), $stored);
        }

        return GatewayResult::refunded($stored);
    }

    // ────────────────────────────────────────────────────────

    /**
     * 승인·망취소가 같은 필드를 쓴다(원문 STEP3 · 망취소).
     *
     * timestamp 는 **새로 찍는다** — 원문 "인증요청 시 timestamp값과 상이".
     *
     * @return array<string, string>
     */
    private function authFields(string $token, int $amountMinor): array
    {
        $ts = ($this->clockMs)();

        return [
            'mid' => $this->mid,
            'authToken' => $token,
            'timestamp' => (string) $ts,
            'signature' => InicisHash::authSignature($token, $ts),
            'verification' => InicisHash::authVerification($token, $this->signKey, $ts),
            'charset' => 'UTF-8',
            'format' => 'JSON',

            // 원문 "인증가격 옵션필드". 우리 행의 금액을 실어 PG 쪽에서도 대조하게 한다.
            'price' => (string) $amountMinor,
        ];
    }

    /** @param array<string, mixed> $body */
    private function mismatch(array $body, string $paymentUid, int $amountMinor): ?string
    {
        if (self::str($body['MOID'] ?? null) !== $paymentUid) {
            return 'MOID';
        }

        $price = self::str($body['TotPrice'] ?? null);

        if (!ctype_digit($price) || (int) $price !== $amountMinor) {
            return 'TotPrice '.$price.' != '.$amountMinor;
        }

        if (isset($body['mid']) && self::str($body['mid']) !== $this->mid) {
            return 'mid';
        }

        if (self::str($body['tid'] ?? null) === '') {
            // 환불할 방법이 없는 승인은 받지 않는다
            return 'tid-empty';
        }

        return null;
    }

    /**
     * INIAPI timestamp [YYYYMMDDhhmmss].
     *
     * [추정] 한국 시각이다. 원문에 시간대가 없다. 예시(`20191128121211`)가 KST
     * 업무 시간대라 그렇게 둔다 — 틀리면 해시가 아니라 시각 검사에서 걸릴 것이고,
     * 무과금 확인(없는 tid 로 환불)에서 드러난다.
     */
    private function kstTimestamp(): string
    {
        $sec = intdiv(($this->clockMs)(), 1000);

        return (new DateTimeImmutable('@'.$sec))
            ->setTimezone(new DateTimeZone('Asia/Seoul'))
            ->format('YmdHis');
    }

    private static function str(mixed $raw): string
    {
        return is_string($raw) || is_int($raw) ? trim((string) $raw) : '';
    }
}
