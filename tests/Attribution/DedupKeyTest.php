<?php

declare(strict_types=1);

namespace App\Tests\Attribution;

use App\Attribution\DedupKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DedupKeyTest extends TestCase
{
    #[Test]
    public function 같은_입력은_항상_같은_키를_만든다(): void
    {
        $a = DedupKey::for('purchase', '01J8XKP2M4');
        $b = DedupKey::for('purchase', '01J8XKP2M4');

        self::assertSame($a, $b);
    }

    #[Test]
    public function 종류가_다르면_키도_다르다(): void
    {
        self::assertNotSame(
            DedupKey::for('purchase', '01J8XKP2M4'),
            DedupKey::for('signup', '01J8XKP2M4'),
        );
    }

    #[Test]
    public function 짧은_키는_사람이_읽을_수_있게_남는다(): void
    {
        self::assertSame('purchase:01J8XKP2M4', DedupKey::for('purchase', '01J8XKP2M4'));
    }

    #[Test]
    public function 앞뒤_공백은_무시된다(): void
    {
        self::assertSame(
            DedupKey::for('purchase', '01J8XKP2M4'),
            DedupKey::for('  purchase  ', "\t01J8XKP2M4\n"),
        );
    }

    #[Test]
    public function 컬럼_길이를_넘지_않는다(): void
    {
        $key = DedupKey::for('purchase', str_repeat('x', 500));

        self::assertLessThanOrEqual(DedupKey::MAX_LENGTH, strlen($key));
    }

    #[Test]
    public function 긴_식별자도_서로_다르면_키가_충돌하지_않는다(): void
    {
        // 잘라내기로 구현하면 앞부분이 같은 긴 식별자들이 한 키로 뭉친다.
        // 그러면 서로 다른 결제가 중복으로 판정돼 전환이 유실된다.
        $long = str_repeat('x', 500);

        self::assertNotSame(
            DedupKey::for('purchase', $long . 'A'),
            DedupKey::for('purchase', $long . 'B'),
        );
    }

    #[Test]
    public function 긴_키에도_종류가_앞에_남는다(): void
    {
        $key = DedupKey::for('purchase', str_repeat('x', 500));

        self::assertStringStartsWith('purchase:', $key);
    }

    #[Test]
    public function 종류가_비어_있으면_거부한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DedupKey::for('   ', '01J8XKP2M4');
    }

    #[Test]
    public function 식별자가_비어_있으면_거부한다(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DedupKey::for('purchase', '');
    }
}
