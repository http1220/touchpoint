<?php

declare(strict_types=1);

namespace App\Tests\Payment\Gateway;

use App\Channel\HttpResponse;
use App\Payment\Gateway\Checkout;
use App\Payment\Gateway\GatewayResult;
use App\Payment\Gateway\InicisGateway;
use App\Payment\Gateway\InicisHash;
use App\Payment\Gateway\PayloadRedactor;
use App\Tests\Channel\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * 실카드 검증을 하지 않기로 했다(plan-multi-pg.md 6장). 그래서 **승인 뒤의
 * 모든 경로를 여기서만 확인한다** — 이 테스트가 운영 검증을 대신하는 게
 * 아니라, 운영에서 확인하지 못한 것의 경계를 적는 것이다.
 */
final class InicisGatewayTest extends TestCase
{
    private const MID = 'INIpayTest';
    private const SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';
    private const UID = '0192f0a1b2c3d4e5f60718293a4b5c6d';
    private const AMOUNT = 3300;
    private const NOW_MS = 1758240000123;   // 2025-09-19 00:00:00.123 UTC

    private const AUTH_URL = 'https://stgstdpay.inicis.com/api/payAuth';
    private const NET_CANCEL_URL = 'https://stgstdpay.inicis.com/api/netCancel';

    public function test_결제창_필드에_서버가_서명한다(): void
    {
        $out = $this->gateway(new FakeHttpClient())->checkout($this->checkout());
        $f = $out['fields'];

        self::assertSame('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', $out['action']);
        self::assertSame(self::UID, $f['oid']);
        self::assertSame('3300', $f['price']);
        self::assertSame((string) self::NOW_MS, $f['timestamp']);
        self::assertSame(InicisHash::signature(self::UID, self::AMOUNT, self::NOW_MS), $f['signature']);
        self::assertSame(InicisHash::verification(self::UID, self::AMOUNT, self::SIGN_KEY, self::NOW_MS), $f['verification']);
        self::assertSame(InicisHash::mKey(self::SIGN_KEY), $f['mKey']);

        // ISO 4217 이 아니다
        self::assertSame('WON', $f['currency']);

        // 없으면 idc_name 이 안 와서 authUrl 을 검사할 수 없다
        self::assertSame('centerCd(Y)', $f['acceptmethod']);
        self::assertSame('Y', $f['use_chkfake']);

        // signKey 자체는 브라우저로 나가지 않는다
        self::assertNotContains(self::SIGN_KEY, $f);
    }

    public function test_KRW_가_아니면_결제창을_만들지_않는다(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway(new FakeHttpClient())->checkout(new Checkout(self::UID, 999, 'USD', 'c', 'n', '010', 'e@x', 'r', 'c'));
    }

    /**
     * 브라우저 입력만 보고 거절한 것은 rejected 다 — failed 가 아니다.
     * failed 로 적으면 위조 복귀 요청 하나로 남의 결제를 실패시킬 수 있다.
     *
     * @dataProvider 요청_전_거절
     */
    public function test_승인_요청_전에_거절하면_HTTP_를_부르지_않고_장부도_바꾸지_않는다(array $override, string $reasonPrefix): void
    {
        $http = new FakeHttpClient();
        $r = $this->gateway($http)->confirm($override + $this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertSame(GatewayResult::REJECTED, $r->outcome);
        self::assertStringStartsWith($reasonPrefix, (string) $r->reason);
        self::assertSame(0, $http->calls, '거절은 HTTP 0회여야 한다');
        self::assertFalse($r->mustCompensate(), '승인 요청 전이면 되돌릴 것이 없다');
    }

    public static function 요청_전_거절(): array
    {
        return [
            '인증 실패로 돌아옴'      => [['resultCode' => 'V801', 'resultMsg' => '취소'], 'auth-failed: V801'],
            '다른 상점'              => [['mid' => 'OTHERMID00'], 'mid-mismatch'],
            '다른 주문'              => [['orderNumber' => 'ffffffffffffffffffffffffffffffff'], 'order-mismatch'],
            'authToken 없음'         => [['authToken' => ''], 'no-auth-token'],
            '공격자 승인 URL'         => [['authUrl' => 'https://evil.example/api/payAuth'], 'untrusted-auth-url'],
            '센터와 다른 호스트'       => [['idc_name' => 'fc'], 'untrusted-auth-url'],
            '공격자 망취소 URL'        => [['netCancelUrl' => 'https://evil.example/cancel'], 'untrusted-net-cancel-url'],
        ];
    }

    public function test_승인되면_tid_를_잡고_카드번호와_구매자_정보는_남기지_않는다(): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = $this->approval();

        $r = $this->gateway($http)->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertTrue($r->isCaptured());
        self::assertSame(['tid' => 'StdpayCARDINIpayTest20250919090000123456'], $r->refs);

        // 승인 요청은 우리가 검사한 URL 로, 새 timestamp 와 서명으로 나갔다
        self::assertSame(self::AUTH_URL, $http->requests[0]['url']);
        $form = $http->requests[0]['form'];
        self::assertSame(InicisHash::authSignature('TOKEN-1', self::NOW_MS), $form['signature']);
        self::assertSame(InicisHash::authVerification('TOKEN-1', self::SIGN_KEY, self::NOW_MS), $form['verification']);
        self::assertSame('3300', $form['price']);

        // C5 · B9: 카드번호·구매자 정보는 저장 대상에 없다. 원문은 해시로만
        foreach (['CARD_Num', 'buyerName', 'buyerTel', 'buyerEmail', 'custEmail'] as $key) {
            self::assertArrayNotHasKey($key, $r->stored);
        }
        self::assertSame('0000', $r->stored['resultCode']);
        self::assertSame(64, strlen($r->stored[PayloadRedactor::HASH_KEY]));

        // 되돌릴 값은 들고 있다 — 장부 실패 시 망취소용. 하지만 지금 되돌릴 필요는 없다
        self::assertFalse($r->mustCompensate());
        self::assertSame(self::NET_CANCEL_URL, $r->compensation['netCancelUrl']);
    }

    public function test_응답이_없으면_failed_가_아니라_unknown_이고_되돌려야_한다(): void
    {
        $http = new FakeHttpClient(null, '', 'Operation timed out after 10000 milliseconds');

        $r = $this->gateway($http)->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertSame(GatewayResult::UNKNOWN, $r->outcome);
        self::assertTrue($r->mustCompensate(), 'PG 에 승인이 났을 수 있다 — B4');
    }

    public function test_읽을_수_없는_응답도_unknown_이다(): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = new HttpResponse(502, '<html>Bad Gateway</html>');

        $r = $this->gateway($http)->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertSame(GatewayResult::UNKNOWN, $r->outcome);
        self::assertTrue($r->mustCompensate());
    }

    public function test_PG_가_승인을_거절하면_되돌릴_것이_없다(): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = new HttpResponse(200, (string) json_encode(['resultCode' => 'R201', 'resultMsg' => '한도초과']));

        $r = $this->gateway($http)->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertSame(GatewayResult::FAILED, $r->outcome);
        self::assertStringStartsWith('approval-failed: R201', (string) $r->reason);
        self::assertFalse($r->mustCompensate());
    }

    /** @dataProvider 불일치 */
    public function test_승인은_났는데_우리_행과_다르면_받지_않고_되돌린다(array $override, string $what): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = $this->approval($override);

        $r = $this->gateway($http)->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertSame(GatewayResult::FAILED, $r->outcome);
        self::assertStringContainsString($what, (string) $r->reason);
        self::assertTrue($r->mustCompensate());
    }

    public static function 불일치(): array
    {
        return [
            '금액'        => [['TotPrice' => '100'], 'TotPrice'],
            '금액 형식'    => [['TotPrice' => '3300.0'], 'TotPrice'],
            '주문번호'     => [['MOID' => 'ffffffffffffffffffffffffffffffff'], 'MOID'],
            '상점'        => [['mid' => 'OTHERMID00'], 'mid'],
            'tid 없음'    => [['tid' => ''], 'tid-empty'],
        ];
    }

    public function test_망취소는_검사한_URL_로_새_서명과_함께_간다(): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = $this->approval();
        $http->queue[] = new HttpResponse(200, (string) json_encode(['resultCode' => '0000', 'resultMsg' => '망취소 성공']));

        $gateway = $this->gateway($http);
        $r = $gateway->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertTrue($gateway->compensate($r, self::AMOUNT));
        self::assertCount(2, $http->requests);
        self::assertSame(self::NET_CANCEL_URL, $http->requests[1]['url']);
        self::assertSame('TOKEN-1', $http->requests[1]['form']['authToken']);
    }

    public function test_망취소가_실패하면_false(): void
    {
        $http = new FakeHttpClient(null, '', 'connection reset');
        $gateway = $this->gateway($http);
        $r = $gateway->confirm($this->authReturn(), self::UID, self::AMOUNT, 'KRW');

        self::assertFalse($gateway->compensate($r, self::AMOUNT));
    }

    public function test_환불은_KST_시각과_SHA512_해시로_INIAPI_스테이징에_간다(): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = new HttpResponse(200, (string) json_encode(['resultCode' => '00', 'resultMsg' => '정상처리', 'cancelDate' => '20250919', 'cancelTime' => '090000']));

        $r = $this->gateway($http)->refund(['tid' => 'StdpayCARDINIpayTest20250919090000123456'], '테스트 당일 환불');

        self::assertSame(GatewayResult::REFUNDED, $r->outcome);
        self::assertSame('https://stginiapi.inicis.com/api/v1/refund', $http->lastUrl);

        $form = $http->lastForm;
        self::assertSame('20250919090000', $form['timestamp'], 'NOW_MS 는 UTC 자정 → KST 09시');
        self::assertSame(
            InicisHash::refund('ItEQKi3rY7uvDS8l', 'Refund', 'Card', '20250919090000', '203.0.113.10', self::MID, 'StdpayCARDINIpayTest20250919090000123456'),
            $form['hashData']
        );
    }

    public function test_환불_성공_코드는_00_이고_0000_이_아니다(): void
    {
        $http = new FakeHttpClient();
        $http->queue[] = new HttpResponse(200, (string) json_encode(['resultCode' => '0000']));

        $r = $this->gateway($http)->refund(['tid' => 'T'], 'x');

        self::assertSame(GatewayResult::FAILED, $r->outcome);
    }

    public function test_환불_키가_없으면_요청하지_않는다(): void
    {
        $http = new FakeHttpClient();
        $gateway = new InicisGateway($http, 'test', self::MID, self::SIGN_KEY, '', '', 10000, static fn (): int => self::NOW_MS);

        self::assertSame('iniapi-not-configured', $gateway->refund(['tid' => 'T'], 'x')->reason);
        self::assertSame(0, $http->calls);
    }

    // ────────────────────────────────────────────────────────

    private function gateway(FakeHttpClient $http): InicisGateway
    {
        return new InicisGateway(
            $http, 'test', self::MID, self::SIGN_KEY, 'ItEQKi3rY7uvDS8l', '203.0.113.10', 10000,
            static fn (): int => self::NOW_MS
        );
    }

    private function checkout(): Checkout
    {
        return new Checkout(
            self::UID, self::AMOUNT, 'KRW', '코인 30', '시연 구매자', '010-0000-0000', 'demo@example.com',
            'https://app.example.com/pay/inicis/return', 'https://app.example.com/pay/inicis/close'
        );
    }

    /** STEP2 복귀 본문. 원문 필드 그대로 */
    private function authReturn(): array
    {
        return [
            'resultCode' => '0000',
            'resultMsg' => '성공',
            'mid' => self::MID,
            'orderNumber' => self::UID,
            'authToken' => 'TOKEN-1',
            'idc_name' => 'stg',
            'authUrl' => self::AUTH_URL,
            'netCancelUrl' => self::NET_CANCEL_URL,
            'charset' => 'UTF-8',
        ];
    }

    /** STEP4 승인 결과. 카드번호·구매자 정보가 **실려 온다** */
    private function approval(array $override = []): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode($override + [
            'resultCode' => '0000',
            'resultMsg' => '정상처리되었습니다.',
            'tid' => 'StdpayCARDINIpayTest20250919090000123456',
            'mid' => self::MID,
            'MOID' => self::UID,
            'TotPrice' => '3300',
            'payMethod' => 'Card',
            'applDate' => '20250919',
            'applTime' => '090000',
            'applNum' => '12345678',
            'CARD_Num' => '12345678****123*',
            'CARD_Code' => '11',
            'buyerName' => '시연 구매자',
            'buyerTel' => '010-0000-0000',
            'buyerEmail' => 'demo@example.com',
            'custEmail' => 'demo@example.com',
        ], JSON_UNESCAPED_UNICODE));
    }
}
