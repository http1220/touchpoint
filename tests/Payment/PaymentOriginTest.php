<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\PaymentOrigin;
use PHPUnit\Framework\TestCase;

final class PaymentOriginTest extends TestCase
{
    /** @dataProvider 운영에_있는_접두어 */
    public function test_운영에_남은_키는_출처가_붙는다(string $key, string $label, bool $byVisitor): void
    {
        self::assertSame($label, PaymentOrigin::label($key));
        self::assertSame($byVisitor, PaymentOrigin::byVisitor($key));
    }

    /** 09-20 운영 payments 의 접두어 전부(vt- 제외 — 아래) */
    public static function 운영에_있는_접두어(): array
    {
        return [
            'demo'      => ['demo-6b1f0c2e-1d3a-4c55-9e0a-2f6d8b7c1a90', '카드 없는 버튼', true],
            'pay'       => ['pay-12f29c01-6615-4608-bf57-3e0abc4c8a06', '카드 결제창', true],
            'interview' => ['interview-dup-1789790369467363236', '면접 시연 대본', false],
            'd3'        => ['d3-1789357066', '동시성 측정 (D-3)', false],
            'ctl'       => ['ctl-1789357243', '동시성 측정 (D-3)', false],
            'ctl2'      => ['ctl2-1789357384', '동시성 측정 (D-3)', false],
            'rf'        => ['rf-a-1789428974996865438', '환불 시험', false],
            'e2e'       => ['e2e-meta-1789398778', '매체 전송 확인', false],
            'e2e2'      => ['e2e2-b-1789425402', '매체 전송 확인', false],
            'verify'    => ['verify-stub-1789775635', '배포 확인', false],
        ];
    }

    public function test_출처를_모르는_키는_지어내지_않는다(): void
    {
        // vt- 는 09-14 에 만든 행인데 기록에서 무엇이었는지 찾지 못했다
        self::assertSame(PaymentOrigin::OTHER, PaymentOrigin::label('vt-1789358889'));
        self::assertFalse(PaymentOrigin::byVisitor('vt-1789358889'));
        self::assertSame(PaymentOrigin::OTHER, PaymentOrigin::label(''));
    }

    public function test_구분자까지_본다(): void
    {
        // 'demo' 로 시작하지만 'demo-' 는 아니다 — 방문자 버튼으로 세면 안 된다
        self::assertSame(PaymentOrigin::OTHER, PaymentOrigin::label('demonstration-1'));
        self::assertSame(PaymentOrigin::OTHER, PaymentOrigin::label('payload-1'));
    }
}
