<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Parser;

use App\Infrastructure\Parser\FutbinJsonPriceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FutbinJsonPriceParserTest extends TestCase
{
    private const SOURCE_URL = 'https://www.futbin.com/26/playerPrices?player=231747';

    private FutbinJsonPriceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FutbinJsonPriceParser();
    }

    public function testParsesRealFutbinFixture(): void
    {
        $json = (string) file_get_contents(\dirname(__DIR__, 3).'/fixtures/futbin/player-231747.json');

        $quote = $this->parser->parse($json, 231747, self::SOURCE_URL);

        self::assertNotNull($quote);
        self::assertSame(231747, $quote->futbinId);
        self::assertSame('Kylian Mbappé', $quote->name);
        self::assertSame(91, $quote->rating);
        self::assertSame('ST', $quote->position);
        self::assertSame(10_900_000, $quote->pricePs);
        self::assertSame(10_750_000, $quote->priceXbox);
        self::assertSame(11_000_000, $quote->pricePc);
        self::assertSame(self::SOURCE_URL, $quote->sourceUrl);
    }

    public function testFallsBackToNextPriceFieldWhenLowestIsMissing(): void
    {
        $json = json_encode([
            '1' => [
                'name' => 'Fallback Player',
                'prices' => [
                    'ps' => ['LCPrice' => '0', 'LCPrice2' => '', 'LCPrice3' => '12500'],
                    'xbox' => ['MinPrice' => 900],
                    'pc' => ['maxPrice' => '1.2m'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $quote = $this->parser->parse($json, 1, self::SOURCE_URL);

        self::assertNotNull($quote);
        self::assertSame(12_500, $quote->pricePs);
        self::assertSame(900, $quote->priceXbox);
        self::assertSame(1_200_000, $quote->pricePc);
    }

    #[DataProvider('priceFormatProvider')]
    public function testParsesFutbinPriceFormats(mixed $raw, ?int $expected): void
    {
        $json = json_encode([
            '1' => ['name' => 'X', 'prices' => ['ps' => ['LCPrice' => $raw]]],
        ], \JSON_THROW_ON_ERROR);

        $quote = $this->parser->parse($json, 1, self::SOURCE_URL);

        self::assertNotNull($quote);
        self::assertSame($expected, $quote->pricePs);
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function priceFormatProvider(): iterable
    {
        yield 'целое число' => [12_500, 12_500];
        yield 'строка' => ['12500', 12_500];
        yield 'с запятыми' => ['1,250,000', 1_250_000];
        yield 'с пробелами' => ['1 250 000', 1_250_000];
        yield 'тысячи k' => ['12.5k', 12_500];
        yield 'миллионы M' => ['10.9M', 10_900_000];
        yield 'ноль' => ['0', null];
        yield 'ноль числом' => [0, null];
        yield 'пусто' => ['', null];
        yield 'не число' => ['n/a', null];
        yield 'null' => [null, null];
    }

    public function testNameFallsBackToPlaceholder(): void
    {
        $json = json_encode(['1' => ['prices' => ['ps' => ['LCPrice' => 100]]]], \JSON_THROW_ON_ERROR);

        $quote = $this->parser->parse($json, 1, self::SOURCE_URL);

        self::assertNotNull($quote);
        self::assertSame('Player #1', $quote->name);
        self::assertNull($quote->rating);
        self::assertNull($quote->position);
    }

    public function testReturnsNullOnMalformedOrUnrelatedPayload(): void
    {
        self::assertNull($this->parser->parse('not json', 1, self::SOURCE_URL));
        self::assertNull($this->parser->parse('null', 1, self::SOURCE_URL));
        self::assertNull($this->parser->parse('{"999":{"prices":{}}}', 1, self::SOURCE_URL));
        self::assertNull($this->parser->parse('{"1":{"prices":"broken"}}', 1, self::SOURCE_URL));
    }

    public function testPlatformWithoutPricesYieldsNulls(): void
    {
        $json = json_encode([
            '1' => ['name' => 'X', 'prices' => ['ps' => ['LCPrice' => 100]]],
        ], \JSON_THROW_ON_ERROR);

        $quote = $this->parser->parse($json, 1, self::SOURCE_URL);

        self::assertNotNull($quote);
        self::assertSame(100, $quote->pricePs);
        self::assertNull($quote->priceXbox);
        self::assertNull($quote->pricePc);
    }
}
