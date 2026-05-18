<?php

declare(strict_types=1);

namespace App\Application\Scraping\UseCase;

use App\Application\Scraping\Message\ProcessParsedDataMessage;
use App\Domain\Scraping\Contract\BrowserClientInterface;
use App\Domain\Scraping\Contract\PageParserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ScrapeCatalogUseCase
{
    public function __construct(
        private BrowserClientInterface $browser,
        private PageParserInterface $parser,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(string $catalogPath): int
    {
        $html = $this->browser->fetch($catalogPath);
        $items = $this->parser->parse($html, $catalogPath);

        foreach ($items as $item) {
            $this->bus->dispatch(new ProcessParsedDataMessage(
                externalId: $item->externalId,
                title: $item->title,
                description: $item->description,
                category: $item->category,
                sourceUrl: $item->sourceUrl,
                scrapedAt: $item->scrapedAt,
            ));
        }

        $this->logger->info('Catalog scraped', [
            'path' => $catalogPath,
            'count' => \count($items),
        ]);

        return \count($items);
    }
}
