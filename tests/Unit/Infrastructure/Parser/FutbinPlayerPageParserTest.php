<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Parser;

use App\Infrastructure\Parser\FutbinPlayerPageParser;
use PHPUnit\Framework\TestCase;

final class FutbinPlayerPageParserTest extends TestCase
{
    private FutbinPlayerPageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FutbinPlayerPageParser();
    }

    public function testParsesPageWithEmbeddedPriceJson(): void
    {
        $html = <<<'HTML'
        <html><head><meta charset="utf-8"><title>Player</title></head><body>
            <h1>Kylian Mbappe</h1>
            <div class="pcdisplay-rat">91</div>
            <div class="pcdisplay-pos">ST</div>
            <script>
                var playerPrices = {"ps":{"LCPrice":"10900000","LCPrice2":"10850000"},
                                    "xbox":{"LCPrice":"10750000"},
                                    "pc":{"LCPrice":"11000000"}};
            </script>
        </body></html>
        HTML;

        $quote = $this->parser->parse($html, 'https://www.futbin.com/26/player/231747/mbappe');

        self::assertNotNull($quote);
        self::assertSame(231747, $quote->futbinId);
        self::assertSame('Kylian Mbappe', $quote->name);
        self::assertSame(91, $quote->rating);
        self::assertSame('ST', $quote->position);
        self::assertSame(10_900_000, $quote->pricePs);
        self::assertSame(10_750_000, $quote->priceXbox);
        self::assertSame(11_000_000, $quote->pricePc);
        self::assertSame('https://www.futbin.com/26/player/231747/mbappe', $quote->sourceUrl);
    }

    public function testFallsBackToDomWhenNoPriceJsonPresent(): void
    {
        $html = <<<'HTML'
        <html><head><meta charset="utf-8"></head><body>
            <div class="playercard-26-name">Erling Haaland</div>
            <div class="playercard-26-rating">92</div>
            <div class="playercard-26-position">ST</div>
            <div class="price ps"><span class="price-value">1.25M</span></div>
            <div class="price xbox"><span class="price-value">1,200,000</span></div>
            <div class="price pc"><span class="price-value">N/A</span></div>
        </body></html>
        HTML;

        $quote = $this->parser->parse($html, 'https://www.futbin.com/26/player/246669/haaland');

        self::assertNotNull($quote);
        self::assertSame(246669, $quote->futbinId);
        self::assertSame('Erling Haaland', $quote->name);
        self::assertSame(92, $quote->rating);
        self::assertSame('ST', $quote->position);
        self::assertSame(1_250_000, $quote->pricePs);
        self::assertSame(1_200_000, $quote->priceXbox);
        self::assertNull($quote->pricePc, 'N/A не должно превращаться в цену');
    }

    public function testFallsBackToPlayerIdAttributeWhenUrlHasNoId(): void
    {
        $html = '<html><body data-player-id="777"><h1>Someone</h1>'
            .'<script>{"ps":{"LCPrice":"1500"}}</script></body></html>';

        $quote = $this->parser->parse($html, 'https://www.futbin.com/players');

        self::assertNotNull($quote);
        self::assertSame(777, $quote->futbinId);
        self::assertSame(1_500, $quote->pricePs);
    }

    public function testReturnsNullWhenPlayerIdCannotBeResolved(): void
    {
        self::assertNull($this->parser->parse('<html><body><h1>Someone</h1></body></html>', 'https://www.futbin.com/players'));
    }

    public function testReturnsNullWhenNeitherPricesNorNameFound(): void
    {
        $html = '<html><head><meta charset="utf-8"></head><body><div class="empty"></div></body></html>';

        self::assertNull($this->parser->parse($html, 'https://www.futbin.com/26/player/231747/mbappe'));
    }

    public function testNameFallsBackToPlaceholderWhenHeadingMissing(): void
    {
        $html = '<html><body><script>{"ps":{"LCPrice":"2000"}}</script></body></html>';

        $quote = $this->parser->parse($html, 'https://www.futbin.com/26/player/231747/mbappe');

        self::assertNotNull($quote);
        self::assertSame('Player #231747', $quote->name);
        self::assertNull($quote->rating);
        self::assertNull($quote->position);
    }
}
