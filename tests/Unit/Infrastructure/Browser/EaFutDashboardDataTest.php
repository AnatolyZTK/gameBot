<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Browser;

use App\Infrastructure\Browser\EaFutDashboardData;
use PHPUnit\Framework\TestCase;

final class EaFutDashboardDataTest extends TestCase
{
    public function testFailureIsUnauthenticatedAndCarriesError(): void
    {
        $data = EaFutDashboardData::failure('сессия истекла');

        self::assertFalse($data->authenticated);
        self::assertSame('сессия истекла', $data->error);
        self::assertNull($data->coins);
        self::assertSame([], $data->tiles);
    }

    public function testSuccessfulDashboardKeepsCoinsAndTiles(): void
    {
        $tiles = [['title' => 'Coins', 'text' => '1 000', 'lines' => ['1 000']]];

        $data = new EaFutDashboardData('1 000', $tiles, true);

        self::assertTrue($data->authenticated);
        self::assertSame('1 000', $data->coins);
        self::assertSame($tiles, $data->tiles);
        self::assertNull($data->error);
    }
}
