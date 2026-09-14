<?php

declare(strict_types=1);

namespace App\Tests\Attribution;

use App\Attribution\AdOptOut;
use PHPUnit\Framework\TestCase;

final class AdOptOutTest extends TestCase
{
    public function test_정확히_1_일_때만_거부다(): void
    {
        self::assertTrue(AdOptOut::isOn('1'));

        foreach (['', '0', 'true', 'yes', ' 1', null, 1, ['1']] as $v) {
            self::assertFalse(AdOptOut::isOn($v), var_export($v, true));
        }
    }
}
