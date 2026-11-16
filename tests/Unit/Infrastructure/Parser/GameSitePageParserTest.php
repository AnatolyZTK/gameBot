<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Parser;

use App\Infrastructure\Parser\GameSitePageParser;
use PHPUnit\Framework\TestCase;

final class GameSitePageParserTest extends TestCase
{
    private GameSitePageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GameSitePageParser();
    }

    public function testParsesItemsWithAllFields(): void
    {
        $html = <<<'HTML'
        <html><head><meta charset="utf-8"></head><body>
            <div class="list">
                <article data-item-id="101">
                    <h2>  Первый товар  </h2>
                    <p class="description">Описание первого</p>
                    <span class="category">moto</span>
                </article>
                <article data-item-id="102">
                    <h2>Второй товар</h2>
                    <p class="description">Описание второго</p>
                    <span class="category">auto</span>
                </article>
            </div>
        </body></html>
        HTML;

        $items = $this->parser->parse($html, '/catalog');

        self::assertCount(2, $items);
        self::assertSame(101, $items[0]->externalId);
        self::assertSame('Первый товар', $items[0]->title, 'Заголовок должен быть обрезан по краям');
        self::assertSame('Описание первого', $items[0]->description);
        self::assertSame('moto', $items[0]->category);
        self::assertSame('/catalog#item-101', $items[0]->sourceUrl);
        self::assertSame(102, $items[1]->externalId);
    }

    public function testCategoryFallsBackToUnknown(): void
    {
        $html = '<div data-item-id="7"><h2>Без категории</h2></div>';

        $items = $this->parser->parse($html, '/catalog');

        self::assertCount(1, $items);
        self::assertSame('unknown', $items[0]->category);
        self::assertSame('', $items[0]->description);
    }

    public function testSkipsItemsWithoutTitleOrWithInvalidId(): void
    {
        $html = <<<'HTML'
        <div data-item-id="0"><h2>Нулевой id</h2></div>
        <div data-item-id="abc"><h2>Нечисловой id</h2></div>
        <div data-item-id="5"><h2>   </h2></div>
        <div data-item-id="6"><h2>Валидный</h2></div>
        HTML;

        $items = $this->parser->parse($html, '/catalog');

        self::assertCount(1, $items);
        self::assertSame(6, $items[0]->externalId);
    }

    public function testReturnsEmptyArrayWhenNothingMatches(): void
    {
        self::assertSame([], $this->parser->parse('<html><body><p>no items here</p></body></html>', '/catalog'));
    }

    /**
     * Известное ограничение: пустой ответ страницы роняет парсер, а не даёт [].
     * Если `loadHTML` перестанут вызывать на пустой строке — тест напомнит поправить ожидание.
     */
    public function testEmptyHtmlThrowsInsteadOfReturningEmptyList(): void
    {
        $this->expectException(\ValueError::class);

        $this->parser->parse('', '/catalog');
    }

    /**
     * Известное ограничение: DOMDocument без объявления кодировки читает ответ как ISO-8859-1,
     * поэтому UTF-8 без <meta charset> превращается в мохибейк.
     */
    public function testHtmlWithoutCharsetDeclarationIsMisdecoded(): void
    {
        $items = $this->parser->parse('<div data-item-id="1"><h2>Первый товар</h2></div>', '/catalog');

        self::assertCount(1, $items);
        self::assertNotSame('Первый товар', $items[0]->title);
    }

    public function testSetsScrapedAtTimestamp(): void
    {
        $before = new \DateTimeImmutable();

        $items = $this->parser->parse('<div data-item-id="1"><h2>Товар</h2></div>', '/catalog');

        self::assertGreaterThanOrEqual($before->getTimestamp(), $items[0]->scrapedAt->getTimestamp());
    }
}
