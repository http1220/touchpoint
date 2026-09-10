<?php

declare(strict_types=1);

namespace App\Tests\Attribution;

use App\Attribution\BridgeDestination;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BridgeDestinationTest extends TestCase
{
    private const UID = '0192f3a4b5c6d7e8f9a0b1c2d3e4f5a6';

    private const QUERY = [
        'work' => '8733',
        'mode' => 'passthru',
        'pid' => 'google',
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'gclid' => 'EAIaIQobChMI',
        'ref' => '남의파라미터',
    ];

    public function test_기본은_방문식별자만_넘긴다(): void
    {
        $url = BridgeDestination::build('https://lp.example.com', '8733', self::UID, self::QUERY);

        self::assertSame('https://lp.example.com/l/8733?vid='.self::UID, $url);
    }

    public function test_방문식별자_방식은_유입파라미터를_목적지에_남기지_않는다(): void
    {
        $url = BridgeDestination::build('https://lp.example.com', '8733', self::UID, self::QUERY);

        // 주소창을 복사해 공유해도 남의 유입이 내 클릭으로 잡히지 않는 근거다.
        self::assertStringNotContainsString('utm_source', $url);
        self::assertStringNotContainsString('gclid', $url);
    }

    public function test_전량전달_방식은_화이트리스트만_넘긴다(): void
    {
        $url = BridgeDestination::build(
            'https://lp.example.com',
            '8733',
            self::UID,
            self::QUERY,
            BridgeDestination::MODE_PASSTHRU,
        );

        self::assertStringContainsString('utm_source=google', $url);
        self::assertStringContainsString('gclid=EAIaIQobChMI', $url);
        self::assertStringContainsString('pid=google', $url);

        // 화이트리스트 밖은 통과하지 못한다. 브리지가 임의 파라미터의 통로가 되면 안 된다.
        self::assertStringNotContainsString('ref=', $url);
        self::assertStringNotContainsString('mode=', $url);
        self::assertStringNotContainsString('work=', $url);
    }

    public function test_전량전달인데_넘길_게_없으면_물음표를_붙이지_않는다(): void
    {
        $url = BridgeDestination::build(
            'https://lp.example.com',
            '8733',
            self::UID,
            [],
            BridgeDestination::MODE_PASSTHRU,
        );

        self::assertSame('https://lp.example.com/l/8733', $url);
    }

    public function test_빈_문자열_파라미터는_넘기지_않는다(): void
    {
        $url = BridgeDestination::build(
            'https://lp.example.com',
            '8733',
            self::UID,
            ['utm_source' => '  ', 'utm_medium' => 'cpc'],
            BridgeDestination::MODE_PASSTHRU,
        );

        self::assertSame('https://lp.example.com/l/8733?utm_medium=cpc', $url);
    }

    public function test_기준_URL_끝의_슬래시는_중복되지_않는다(): void
    {
        $url = BridgeDestination::build('https://lp.example.com/', '8733', self::UID, []);

        self::assertStringStartsWith('https://lp.example.com/l/8733', $url);
    }

    /**
     * 이 값은 Location 헤더로 나간다. 개행이 섞이면 응답을 쪼갤 수 있고,
     * 경로 조작이 되면 오픈 리다이렉트가 된다.
     *
     * @dataProvider 위험한_작품ID
     */
    public function test_작품ID가_숫자가_아니면_거절한다(string $work): void
    {
        $this->expectException(InvalidArgumentException::class);

        BridgeDestination::build('https://lp.example.com', $work, self::UID, []);
    }

    public static function 위험한_작품ID(): array
    {
        return [
            '개행'        => ["8733\r\nLocation: https://evil.example"],
            '상대경로'    => ['../../evil'],
            '외부주소'    => ['https://evil.example'],
            '빈값'        => [''],
            '문자섞임'    => ['87a33'],
            '너무_김'     => [str_repeat('9', 19)],
        ];
    }

    public function test_방문식별자_형식이_아니면_거절한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BridgeDestination::build('https://lp.example.com', '8733', 'not-a-uid', []);
    }

    /** @dataProvider 모드_입력 */
    public function test_모르는_모드는_기본값으로_떨어진다(?string $raw, string $expected): void
    {
        self::assertSame($expected, BridgeDestination::normalizeMode($raw));
    }

    public static function 모드_입력(): array
    {
        return [
            [null, BridgeDestination::MODE_VID],
            ['', BridgeDestination::MODE_VID],
            ['VID', BridgeDestination::MODE_VID],
            ['  passthru  ', BridgeDestination::MODE_PASSTHRU],
            ['PassThru', BridgeDestination::MODE_PASSTHRU],
            ['무슨모드', BridgeDestination::MODE_VID],
        ];
    }
}
