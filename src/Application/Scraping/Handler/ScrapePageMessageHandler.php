<?php

declare(strict_types=1);

namespace App\Application\Scraping\Handler;

use App\Application\Scraping\Message\ScrapePageMessage;
use App\Application\Scraping\UseCase\ScrapeCatalogUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScrapePageMessageHandler
{
    public function __construct(
        private ScrapeCatalogUseCase $scrapeCatalog,
    ) {
    }

    public function __invoke(ScrapePageMessage $message): void
    {
        $this->scrapeCatalog->execute($message->path);
    }
}
