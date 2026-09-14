<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * 위조 · 시각 창 초과 · 정상 통과.
 *
 * 시각을 주입받는 설계가 여기서 값을 한다 — 재생 창의 경계를
 * `sleep(301)` 없이 못박을 수 있다.
 */
final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'e3b0c44298fc1c149afbf4c8996fb924';
    private const NOW = 1757836800;
    private const BODY = '{"event_id":"evt_1","payment_uid":"0a1b2c3d4e5f60718293a4b5c6d7e8f9","status":"captured"}';

    public function test_스스로_서명한_것은_통과한다(): void
    {
        $sig = new WebhookSignature(self::SECRET);
        $header = $sig->header(self::BODY, self::NOW);

        self::assertTrue($sig->verify($header, self::BODY, self::NOW)->ok);
    }

    /** 헤더 형식은 계획 5장 그대로다. 스텁 PG 와 검증이 같은 문자열을 본다. */
    public function test_헤더_형식(): void
    {
        $header = (new WebhookSignature(self::SECRET))->header(self::BODY, self::NOW);

        self::assertMatchesRegularExpression('/\At=1757836800,v1=[0-9a-f]{64}\z/', $header);
    }

    /**
     * **원본 바이트로 서명한다.**
     *
     * 같은 JSON 을 파싱했다가 재인코딩하면 키 순서와 공백이 달라진다.
     * 그 문자열로 검증하면 정상 웹훅이 401 로 떨어진다.
     */
    public function test_본문이_한_바이트만_달라도_거절한다(): void
    {
        $sig = new WebhookSignature(self::SECRET);
        $header = $sig->header(self::BODY, self::NOW);

        $tampered = str_replace('"captured"', '"captured" ', self::BODY);

        self::assertNotSame(self::BODY, $tampered);
        self::assertFalse($sig->verify($header, $tampered, self::NOW)->ok);
    }

    public function test_금액을_바꿔치기한_본문은_거절한다(): void
    {
        $sig = new WebhookSignature(self::SECRET);
        $body = '{"payment_uid":"0a1b","status":"captured","amount_minor":9900}';
        $header = $sig->header($body, self::NOW);

        $forged = str_replace('9900', '1', $body);

        self::assertFalse($sig->verify($header, $forged, self::NOW)->ok);
    }

    public function test_다른_비밀키로_만든_서명은_거절한다(): void
    {
        $header = (new WebhookSignature('다른키'))->header(self::BODY, self::NOW);

        self::assertFalse((new WebhookSignature(self::SECRET))->verify($header, self::BODY, self::NOW)->ok);
    }

    /**
     * t 를 바꿔치기하면 서명이 깨진다.
     *
     * 타임스탬프가 서명 대상 안에 들어 있기 때문이다. 헤더의 t 만 고쳐
     * 재생 창을 밀어내는 공격이 여기서 막힌다.
     */
    public function test_t_만_고쳐_창_안으로_끌어오면_서명이_깨진다(): void
    {
        $sig = new WebhookSignature(self::SECRET);
        $old = self::NOW - 86400;
        $header = $sig->header(self::BODY, $old);

        $moved = preg_replace('/\At=\d+/', 't='.self::NOW, $header);

        self::assertFalse($sig->verify($moved, self::BODY, self::NOW)->ok);
    }

    /** @dataProvider 시각창 */
    public function test_재생_창은_양쪽으로_닫힌다(int $skewSec, bool $expected): void
    {
        $sig = new WebhookSignature(self::SECRET, 300);
        $header = $sig->header(self::BODY, self::NOW + $skewSec);

        self::assertSame($expected, $sig->verify($header, self::BODY, self::NOW)->ok);
    }

    public static function 시각창(): array
    {
        return [
            '동시' => [0, true],
            '과거 299초' => [-299, true],
            '과거 300초 — 경계 포함' => [-300, true],
            '과거 301초' => [-301, false],
            '어제' => [-86400, false],

            // 미래도 막는다. 과거만 막으면 t 를 앞당긴 서명 하나로
            // 재생 창이 사실상 영구해진다.
            '미래 300초 — 경계 포함' => [300, true],
            '미래 301초' => [301, false],
        ];
    }

    public function test_창_크기는_주입된다(): void
    {
        $tight = new WebhookSignature(self::SECRET, 5);
        $header = $tight->header(self::BODY, self::NOW - 10);

        self::assertFalse($tight->verify($header, self::BODY, self::NOW)->ok);
        self::assertTrue((new WebhookSignature(self::SECRET, 300))->verify($header, self::BODY, self::NOW)->ok);
    }

    /** @dataProvider 깨진헤더 */
    public function test_헤더가_형식이_아니면_거절한다(?string $header): void
    {
        $verdict = (new WebhookSignature(self::SECRET))->verify($header, self::BODY, self::NOW);

        self::assertFalse($verdict->ok);
        self::assertIsString($verdict->reason);
    }

    public static function 깨진헤더(): array
    {
        $hex = str_repeat('ab', 32);

        return [
            '헤더 없음' => [null],
            '빈 문자열' => [''],
            '공백' => ['   '],
            't 없음' => ['v1='.$hex],
            'v1 없음' => ['t=1757836800'],
            '값 없음' => ['t=,v1='],
            'hex 아님' => ['t=1757836800,v1='.str_repeat('zz', 32)],
            '길이 부족' => ['t=1757836800,v1=abcd'],
            't 가 숫자 아님' => ['t=어제,v1='.$hex],
            '등호 없음' => ['t1757836800v1'.$hex],
            '다른 스킴' => ['t=1757836800,v2='.$hex],
        ];
    }

    /**
     * 비밀키가 비면 **전부 거절**이다.
     *
     * 빈 키로 HMAC 을 계산해 비교하면 계산식이 공개돼 있으니 누구나
     * 통과한다. 설정 누락은 열림이 아니라 닫힘으로 넘어져야 한다.
     */
    public function test_비밀키가_비면_자기가_만든_서명도_거절한다(): void
    {
        $sig = new WebhookSignature('');
        $header = $sig->header(self::BODY, self::NOW);

        $verdict = $sig->verify($header, self::BODY, self::NOW);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('PG_WEBHOOK_SECRET', (string) $verdict->reason);
    }

    /** 대문자 hex 로 보내는 구현이 있다. 서명 값 자체는 대소문자를 가리지 않는다. */
    public function test_v1_은_대문자_hex_도_받는다(): void
    {
        $sig = new WebhookSignature(self::SECRET);
        $header = strtoupper($sig->header(self::BODY, self::NOW));

        // t= 와 v1= 키 자체는 소문자로 돌려놓는다. 값만 대문자다.
        $header = str_replace(['T=', 'V1='], ['t=', 'v1='], $header);

        self::assertTrue($sig->verify($header, self::BODY, self::NOW)->ok);
    }

    /** 통과했을 때는 사유가 없다 — 로그에 남길 것이 없다는 뜻이다. */
    public function test_통과하면_사유가_없다(): void
    {
        $sig = new WebhookSignature(self::SECRET);

        self::assertNull($sig->verify($sig->header(self::BODY, self::NOW), self::BODY, self::NOW)->reason);
    }
}
