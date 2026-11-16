<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scraping\UseCase;

use App\Application\Scraping\Message\ProcessParsedDataMessage;
use App\Application\Scraping\UseCase\ScrapeCatalogUseCase;
use App\Domain\Scraping\Contract\BrowserClientInterface;
use App\Domain\Scraping\Contract\PageParserInterface;
use App\Domain\Scraping\ValueObject\ScrapedItem;
use App\Tests\Support\CollectingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ScrapeCatalogUseCaseTest extends TestCase
{
    public function testDispatchesOneMessagePerParsedItem(): void
    {
        $scrapedAt = new \DateTimeImmutable('2026-03-01 12:00:00');
        $items = [
            new ScrapedItem(1, 'Первый', 'Описание 1', 'moto', '/catalog#item-1', $scrapedAt),
            new ScrapedItem(2, 'Второй', 'Описание 2', 'auto', '/catalog#item-2', $scrapedAt),
        ];

        $bus = new CollectingMessageBus();
        $useCase = new ScrapeCatalogUseCase(
            $this->browserReturning('<html>каталог</html>'),
            $this->parserReturning($items, '<html>каталог</html>', '/catalog'),
            $bus,
            new NullLogger(),
        );

        $count = $useCase->execute('/catalog');

        self::assertSame(2, $count);
        self::assertCount(2, $bus->messages);

        $first = $bus->messages[0];
        self::assertInstanceOf(ProcessParsedDataMessage::class, $first);
        self::assertSame(1, $first->externalId);
        self::assertSame('Первый', $first->title);
        self::assertSame('Описание 1', $first->description);
        self::assertSame('moto', $first->category);
        self::assertSame('/catalog#item-1', $first->sourceUrl);
        self::assertSame($scrapedAt->format('c'), $first->scrapedAt->format('c'));

        self::assertSame(2, $bus->messages[1]->externalId);
    }

    public function testFetchedHtmlAndPathArePassedToParser(): void
    {
        $browser = $this->createMock(BrowserClientInterface::class);
        $browser->expects(self::once())
            ->method('fetch')
            ->with('/catalog/full')
            ->willReturn('<html>полный</html>');

        $parser = $this->createMock(PageParserInterface::class);
        $parser->expects(self::once())
            ->method('parse')
            ->with('<html>полный</html>', '/catalog/full')
            ->willReturn([]);

        $useCase = new ScrapeCatalogUseCase($browser, $parser, new CollectingMessageBus(), new NullLogger());

        self::assertSame(0, $useCase->execute('/catalog/full'));
    }

    public function testDispatchesNothingWhenParserFoundNoItems(): void
    {
        $bus = new CollectingMessageBus();
        $useCase = new ScrapeCatalogUseCase(
            $this->browserReturning('<html></html>'),
            $this->parserReturning([], '<html></html>', '/catalog'),
            $bus,
            new NullLogger(),
        );

        self::assertSame(0, $useCase->execute('/catalog'));
        self::assertSame([], $bus->messages);
    }

    public function testBrowserFailurePropagatesWithoutDispatching(): void
    {
        $browser = $this->createMock(BrowserClientInterface::class);
        $browser->method('fetch')->willThrowException(new \RuntimeException('429 Too Many Requests'));

        $parser = $this->createMock(PageParserInterface::class);
        $parser->expects(self::never())->method('parse');

        $bus = new CollectingMessageBus();
        $useCase = new ScrapeCatalogUseCase($browser, $parser, $bus, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('429 Too Many Requests');

        try {
            $useCase->execute('/catalog');
        } finally {
            self::assertSame([], $bus->messages);
        }
    }

    private function browserReturning(string $html): BrowserClientInterface
    {
        $browser = $this->createMock(BrowserClientInterface::class);
        $browser->method('fetch')->willReturn($html);

        return $browser;
    }

    /** @param list<ScrapedItem> $items */
    private function parserReturning(array $items, string $html, string $path): PageParserInterface
    {
        $parser = $this->createMock(PageParserInterface::class);
        $parser->method('parse')->with($html, $path)->willReturn($items);

        return $parser;
    }
}
