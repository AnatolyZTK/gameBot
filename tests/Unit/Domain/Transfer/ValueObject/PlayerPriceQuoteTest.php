<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer\ValueObject;

use App\Domain\Transfer\Enum\Platform;
use App\Domain\Transfer\ValueObject\PlayerPriceQuote;
use PHPUnit\Framework\TestCase;

final class PlayerPriceQuoteTest extends TestCase
{
    public function testQuoteCarriesPricePerPlatformAndIsImmutable(): void
    {
        $quote = new PlayerPriceQuote(
            futbinId: 231747,
            name: 'Kylian Mbappé',
            rating: 91,
            position: 'ST',
            pricePs: 10_900_000,
            priceXbox: 10_750_000,
            pricePc: 11_000_000,
            sourceUrl: 'https://www.futbin.com/26/player/231747',
        );

        self::assertSame(231747, $quote->futbinId);
        self::assertSame(10_900_000, $quote->pricePs);
        self::assertSame(10_750_000, $quote->priceXbox);
        self::assertSame(11_000_000, $quote->pricePc);
        self::assertCount(3, Platform::cases(), 'В котировке должна быть цена под каждую платформу');

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line проверяем readonly */
        $quote->pricePs = 1;
    }

    public function testMissingPricesStayNull(): void
    {
        $quote = new PlayerPriceQuote(1, 'X', null, null, null, null, null, 'https://example.com');

        self::assertNull($quote->rating);
        self::assertNull($quote->position);
        self::assertNull($quote->pricePs);
        self::assertNull($quote->priceXbox);
        self::assertNull($quote->pricePc);
    }
}
