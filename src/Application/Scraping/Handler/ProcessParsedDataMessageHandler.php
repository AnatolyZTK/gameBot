<?php

declare(strict_types=1);

namespace App\Application\Scraping\Handler;

use App\Application\Scraping\Message\ProcessParsedDataMessage;
use App\Infrastructure\Persistence\Repository\GameItemRepository;
use App\Infrastructure\Search\Manticore\GameItemIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessParsedDataMessageHandler
{
    public function __construct(
        private GameItemRepository $repository,
        private GameItemIndexer $indexer,
    ) {
    }

    public function __invoke(ProcessParsedDataMessage $message): void
    {
        $entity = $this->repository->upsertFromMessage($message);
        $this->indexer->index($entity);
    }
}
