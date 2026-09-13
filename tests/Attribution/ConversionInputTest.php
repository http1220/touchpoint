<?php

declare(strict_types=1);

namespace App\Tests\Attribution;

use App\Attribution\ConversionInput;
use PHPUnit\Framework\TestCase;

final class ConversionInputTest extends TestCase
{
    /** 최소 본문 — 금액 없는 전환(가입)은 type 과 dedup_key 만으로 성립한다. */
    private const MINIMAL = ['type' => 'signup', 'dedup_key' => 'signup:01J8XP'];

    public function test_purchase_본문을_통째로_받는다(): void
    {
        $input = ConversionInput::fromBody([
            'visit_uid' => '01a08c02db59781d9a4425880d006636',
            'user_uid' => 'ffffffff0000000000000000deadbeef',
            'type' => 'purchase',
            'value_minor' => 9900,
            'currency' => 'KRW',
            'dedup_key' => 'payment:01J8XP',
        ]);

        self::assertTrue($input->isValid());
        self::assertSame('01a08c02db59781d9a4425880d006636', $input->visitUid);
        self::assertSame('ffffffff0000000000000000deadbeef', $input->userUid);
        self::assertSame('purchase', $input->type);
        self::assertSame(9900, $input->valueMinor);
        self::assertSame('KRW', $input->currency);
        self::assertSame('payment:01J8XP', $input->dedupKey);
    }

    public function test_금액_없는_전환이_통과한다(): void
    {
        $input = ConversionInput::fromBody(self::MINIMAL);

        self::assertTrue($input->isValid());
        self::assertNull($input->valueMinor);
        self::assertNull($input->currency);
        self::assertNull($input->visitUid);
        self::assertNull($input->userUid);
    }

    /** @dataProvider 전환종류 */
    public function test_type_은_화이트리스트다(mixed $type, bool $expected): void
    {
        $input = ConversionInput::fromBody(['type' => $type, 'dedup_key' => 'k']);

        self::assertSame($expected, $input->isValid());
    }

    public static function 전환종류(): array
    {
        return [
            'signup' => ['signup', true],
            'purchase' => ['purchase', true],
            'subscribe' => ['subscribe', true],

            // 대소문자를 받아 주면 집계 축이 갈린다. 정규화하지 않고 거절한다.
            '대문자' => ['PURCHASE', false],
            '모르는 종류' => ['refund', false],
            '빈 값' => ['', false],
            '없음' => [null, false],
            '배열' => [['purchase'], false],
        ];
    }

    public function test_거절하면_어느_필드인지_남긴다(): void
    {
        $input = ConversionInput::fromBody(['type' => 'refund', 'dedup_key' => 'k']);

        self::assertFalse($input->isValid());
        self::assertSame('type', $input->invalidField);
        self::assertIsString($input->reason);
        self::assertNotSame('', $input->reason);
    }

    public function test_dedup_key_는_필수다(): void
    {
        $input = ConversionInput::fromBody(['type' => 'purchase', 'value_minor' => 9900, 'currency' => 'KRW']);

        self::assertFalse($input->isValid());
        self::assertSame('dedup_key', $input->invalidField);
    }

    /**
     * 컬럼이 VARCHAR(64) 다. 넘치면 잘린 키끼리 겹쳐서 **서로 다른 전환이
     * 중복으로 처리된다** — 조용히 매출이 사라지므로 여기서 막는다.
     */
    public function test_dedup_key_는_64바이트를_넘지_못한다(): void
    {
        $ok = ConversionInput::fromBody(['type' => 'purchase', 'dedup_key' => str_repeat('a', 64),
            'value_minor' => 1, 'currency' => 'KRW']);
        $over = ConversionInput::fromBody(['type' => 'purchase', 'dedup_key' => str_repeat('a', 65),
            'value_minor' => 1, 'currency' => 'KRW']);

        self::assertTrue($ok->isValid());
        self::assertFalse($over->isValid());
        self::assertSame('dedup_key', $over->invalidField);
    }

    /** @dataProvider 금액 */
    public function test_value_minor_는_정수만_받는다(mixed $raw, ?int $expected): void
    {
        $input = ConversionInput::fromBody(self::MINIMAL + ['value_minor' => $raw, 'currency' => 'KRW']);

        if ($expected === null) {
            self::assertFalse($input->isValid());
            self::assertSame('value_minor', $input->invalidField);

            return;
        }

        self::assertTrue($input->isValid());
        self::assertSame($expected, $input->valueMinor);
    }

    public static function 금액(): array
    {
        return [
            '정수' => [9900, 9900],
            '문자열 정수' => ['9900', 9900],
            '0' => [0, 0],

            // 환불·조정은 음수로 들어온다. 컬럼도 부호 있는 BIGINT 다.
            '음수' => [-9900, -9900],

            // JSON 은 9900 과 9900.0 을 구분하지 않고 실어 올 수 있다.
            '소수부 없는 float' => [9900.0, 9900],

            // (int) 로 밀어 넣으면 50원이 조용히 사라진다.
            '소수부 있음' => [99.5, null],
            '소수점 문자열' => ['99.00', null],
            '숫자 아님' => ['9,900', null],
            '참' => [true, null],
            '배열' => [[9900], null],
        ];
    }

    /** @dataProvider 통화 */
    public function test_currency_는_ISO_4217_alpha_3_다(mixed $raw, ?string $expected): void
    {
        $input = ConversionInput::fromBody(self::MINIMAL + ['value_minor' => 1, 'currency' => $raw]);

        if ($expected === null) {
            self::assertFalse($input->isValid());
            self::assertSame('currency', $input->invalidField);

            return;
        }

        self::assertTrue($input->isValid());
        self::assertSame($expected, $input->currency);
    }

    public static function 통화(): array
    {
        return [
            'KRW' => ['KRW', 'KRW'],
            '소문자는 올려 준다' => ['krw', 'KRW'],
            '공백 포함' => [' usd ', 'USD'],
            '숫자 코드' => ['410', null],
            '두 글자' => ['KR', null],
            '네 글자' => ['KRWW', null],
        ];
    }

    /**
     * minor unit 은 통화를 모르면 해석할 수 없다. 한쪽만 받아 두면
     * 표시 단위로 바꾸는 자리에서 기본값을 추측하게 된다.
     */
    public function test_금액과_통화는_함께_온다(): void
    {
        $valueOnly = ConversionInput::fromBody(self::MINIMAL + ['value_minor' => 9900]);
        $currencyOnly = ConversionInput::fromBody(self::MINIMAL + ['currency' => 'KRW']);

        self::assertFalse($valueOnly->isValid());
        self::assertSame('currency', $valueOnly->invalidField);

        self::assertFalse($currencyOnly->isValid());
        self::assertSame('value_minor', $currencyOnly->invalidField);
    }

    /** @dataProvider 식별자 */
    public function test_visit_uid_는_32자_hex_다(mixed $raw, ?string $expected): void
    {
        $input = ConversionInput::fromBody(self::MINIMAL + ['visit_uid' => $raw]);

        if ($expected === null) {
            self::assertFalse($input->isValid());
            self::assertSame('visit_uid', $input->invalidField);

            return;
        }

        self::assertTrue($input->isValid());
        self::assertSame($expected, $input->visitUid);
    }

    public static function 식별자(): array
    {
        return [
            '정상' => ['01a08c02db59781d9a4425880d006636', '01a08c02db59781d9a4425880d006636'],

            // bin2hex() 가 소문자를 낸다. 여기서 맞춰 두지 않으면 대문자로
            // 보낸 요청이 DB 조회에서만 조용히 빗나간다.
            '대문자는 내려 준다' => ['01A08C02DB59781D9A4425880D006636', '01a08c02db59781d9a4425880d006636'],

            '하이픈 표기' => ['01a08c02-db59-781d-9a44-25880d006636', null],
            '길이 부족' => ['01a08c02', null],
            'hex 아님' => [str_repeat('z', 32), null],
        ];
    }

    /** 빈 문자열은 "안 보냈다" 로 본다 — 채워 보내는 클라이언트가 흔하다. */
    public function test_선택_필드의_빈_문자열은_없는_것과_같다(): void
    {
        $input = ConversionInput::fromBody(self::MINIMAL + [
            'visit_uid' => '',
            'user_uid' => '',
            'value_minor' => '',
            'currency' => '',
        ]);

        self::assertTrue($input->isValid());
        self::assertNull($input->visitUid);
        self::assertNull($input->userUid);
        self::assertNull($input->valueMinor);
        self::assertNull($input->currency);
    }

    public function test_빈_본문은_type_에서_걸린다(): void
    {
        $input = ConversionInput::fromBody([]);

        self::assertFalse($input->isValid());
        self::assertSame('type', $input->invalidField);
    }
}
