<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer\Enum;

use App\Domain\Transfer\Enum\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    public function testStoredValuesMatchDatabaseColumn(): void
    {
        self::assertSame(
            ['ps', 'xbox', 'pc'],
            array_map(static fn (Platform $p): string => $p->value, Platform::cases()),
        );
    }

    public function testEveryCaseHasHumanLabel(): void
    {
        foreach (Platform::cases() as $platform) {
            self::assertNotSame('', $platform->label());
        }

        self::assertSame('PlayStation', Platform::Ps->label());
        self::assertSame('Xbox', Platform::Xbox->label());
        self::assertSame('PC', Platform::Pc->label());
    }
}
