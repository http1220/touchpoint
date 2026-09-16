<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\LedgerReconciliation as L;
use PHPUnit\Framework\TestCase;

final class LedgerReconciliationTest extends TestCase
{
    private function pay(string $uid, string $status, int $amount = 9900): array
    {
        return ['uid' => $uid, 'status' => $status, 'amount_minor' => $amount, 'currency' => 'KRW'];
    }

    private function lot(int $remaining = 100, bool $revoked = false): array
    {
        return ['amount' => 100, 'remaining' => $remaining, 'revoked' => $revoked];
    }

    private function kinds(L $r): array
    {
        return array_column($r->issues, 'kind');
    }

    public function test_캡처_코인1_전환1_이면_정상(): void
    {
        $r = L::of([$this->pay('a', 'captured')], ['a' => [$this->lot()]], ['a' => ['purchase' => true]]);

        self::assertTrue($r->isClean());
        self::assertSame(1, $r->checked);
    }

    public function test_돈은_받았는데_코인이_없다_카카오페이_사고의_모양(): void
    {
        $r = L::of([$this->pay('a', 'captured')], [], ['a' => ['purchase' => true]]);

        self::assertSame([L::COIN_MISSING], $this->kinds($r));
    }

    public function test_상품표에_없는_금액이면_코인_없음이_아니라_사람이_메울_건이다(): void
    {
        $r = L::of([$this->pay('a', 'captured', 12345)], [], ['a' => ['purchase' => true]]);

        self::assertSame([L::UNKNOWN_PRICE], $this->kinds($r));
    }

    public function test_코인이_두_번_지급됐다_웹훅_중복_처리의_모양(): void
    {
        $r = L::of([$this->pay('a', 'captured')], ['a' => [$this->lot(), $this->lot()]], ['a' => ['purchase' => true]]);

        self::assertSame([L::COIN_DUPLICATED], $this->kinds($r));
    }

    public function test_확정되지_않은_결제에_코인이_있다(): void
    {
        $r = L::of([$this->pay('a', 'authorized')], ['a' => [$this->lot()]], []);

        self::assertSame([L::UNPAID_GRANT], $this->kinds($r));
    }

    public function test_확정됐는데_매체에_보낼_구매_전환이_없다(): void
    {
        $r = L::of([$this->pay('a', 'captured')], ['a' => [$this->lot()]], []);

        self::assertSame([L::PURCHASE_NOT_REPORTED], $this->kinds($r));
    }

    public function test_환불됐는데_환불_전환이_없다(): void
    {
        $r = L::of([$this->pay('a', 'refunded')], ['a' => [$this->lot(0, true)]], ['a' => ['purchase' => true]]);

        self::assertSame([L::REFUND_NOT_REPORTED], $this->kinds($r));
    }

    public function test_환불됐는데_코인이_남아_있다(): void
    {
        $r = L::of([$this->pay('a', 'refunded')], ['a' => [$this->lot(70)]], ['a' => ['purchase' => true, 'refund' => true]]);

        self::assertSame([L::REVOCATION_MISSING], $this->kinds($r));
    }

    public function test_회수_표시가_생기기_전에_회수된_lot_은_누락으로_세지_않는다(): void
    {
        $r = L::of([$this->pay('a', 'refunded')], ['a' => [$this->lot(0, false)]], ['a' => ['purchase' => true, 'refund' => true]]);

        self::assertTrue($r->isClean());
    }

    public function test_아직_결제_전이면_코인도_전환도_없는_게_정상(): void
    {
        $r = L::of([$this->pay('a', 'created'), $this->pay('b', 'failed')], [], []);

        self::assertTrue($r->isClean());
        self::assertSame(2, $r->checked);
    }

    public function test_종류별로_센다(): void
    {
        $r = L::of(
            [$this->pay('a', 'captured'), $this->pay('b', 'captured'), $this->pay('c', 'captured')],
            ['c' => [$this->lot(), $this->lot()]],
            ['a' => ['purchase' => true], 'b' => ['purchase' => true], 'c' => ['purchase' => true]]
        );

        self::assertSame([L::COIN_DUPLICATED => 1, L::COIN_MISSING => 2], $r->counts());
    }

    // ── 실험 결제 제외 ──────────────────────────────────────

    public function test_제외한_결제는_판정하지_않지만_제외했다는_사실은_남는다(): void
    {
        $r = L::of(
            [$this->pay('ctl', 'captured'), $this->pay('real', 'captured')],
            ['ctl' => [$this->lot(), $this->lot()], 'real' => [$this->lot()]],
            ['ctl' => ['purchase' => true], 'real' => ['purchase' => true]],
            ['ctl' => 'D-3 대조군']
        );

        self::assertTrue($r->isClean(), '대조군의 코인 중복은 판정하지 않는다');
        self::assertSame(['ctl' => 'D-3 대조군'], $r->excluded);
        self::assertSame(1, $r->checked, '판정한 건수에서 뺀다');
    }

    public function test_제외_목록에_있어도_이번_범위에_없는_결제는_제외로_세지_않는다(): void
    {
        $r = L::of([$this->pay('a', 'captured')], ['a' => [$this->lot()]], ['a' => ['purchase' => true]], ['gone' => '파기됨']);

        self::assertSame([], $r->excluded);
        self::assertSame(1, $r->checked);
    }

    public function test_제외는_지정한_결제만_가린다_같은_모양의_다른_어긋남은_잡는다(): void
    {
        $r = L::of(
            [$this->pay('ctl', 'captured'), $this->pay('other', 'captured')],
            ['ctl' => [$this->lot(), $this->lot()], 'other' => [$this->lot(), $this->lot()]],
            ['ctl' => ['purchase' => true], 'other' => ['purchase' => true]],
            ['ctl' => 'D-3 대조군']
        );

        self::assertSame([L::COIN_DUPLICATED => 1], $r->counts());
        self::assertSame('other', $r->issues[0]['uid']);
    }
}
