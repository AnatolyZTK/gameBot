<?php

declare(strict_types=1);

namespace App\Infrastructure\Search\Manticore;

use App\Infrastructure\Persistence\Entity\GameItem;
use Manticoresearch\Client;

final readonly class GameItemIndexer
{
    private const INDEX = 'game_items';

    public function __construct(
        private Client $client,
    ) {
    }

    public function index(GameItem $item): void
    {
        $this->client->index(self::INDEX)->replaceDocuments([
            [
                'id' => $item->getId(),
                'title' => $item->getTitle(),
                'description' => $item->getDescription(),
                'source_url' => $item->getSourceUrl(),
                'external_id' => $item->getExternalId(),
                'category' => $item->getCategory(),
                'scraped_at' => $item->getScrapedAt()->getTimestamp(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $result = $this->client->search([
            'index' => self::INDEX,
            'body' => [
                'query' => [
                    'match' => [
                        '*' => $query,
                    ],
                ],
                'limit' => $limit,
            ],
        ]);

        return $result['hits']['hits'] ?? [];
    }
}
