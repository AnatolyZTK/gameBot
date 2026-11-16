<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scraping;

use App\Application\Scraping\Handler\ProcessParsedDataMessageHandler;
use App\Application\Scraping\Message\ProcessParsedDataMessage;
use App\Infrastructure\Search\Manticore\GameItemIndexer;
use App\Tests\Support\DoctrineTestCase;
use Manticoresearch\Client;
use Manticoresearch\Index;

/**
 * Хендлер пишет в MySQL (source of truth) и затем в индекс Manticore.
 * Manticore здесь замокан на уровне клиента — проверяем, что в индекс уходит
 * уже сохранённая сущность.
 */
final class ProcessParsedDataMessageHandlerTest extends DoctrineTestCase
{
    public function testItemIsPersistedAndIndexed(): void
    {
        $indexedDocuments = [];
        $handler = $this->createHandler($indexedDocuments);
        $scrapedAt = new \DateTimeImmutable('2026-03-01 09:30:00');

        $handler(new ProcessParsedDataMessage(
            externalId: 501,
            title: 'Suzuki Bandit 400',
            description: 'Описание лота',
            category: 'moto',
            sourceUrl: '/catalog#item-501',
            scrapedAt: $scrapedAt,
        ));

        $stored = $this->gameItemRepository()->findOneBy(['externalId' => 501]);
        self::assertNotNull($stored);
        self::assertSame('Suzuki Bandit 400', $stored->getTitle());

        self::assertCount(1, $indexedDocuments);
        $document = $indexedDocuments[0][0];
        self::assertSame($stored->getId(), $document['id']);
        self::assertSame('Suzuki Bandit 400', $document['title']);
        self::assertSame('Описание лота', $document['description']);
        self::assertSame(501, $document['external_id']);
        self::assertSame('moto', $document['category']);
        self::assertSame($scrapedAt->getTimestamp(), $document['scraped_at']);
    }

    public function testRepeatedMessageUpdatesSameRowAndReindexes(): void
    {
        $indexedDocuments = [];
        $handler = $this->createHandler($indexedDocuments);

        $handler(new ProcessParsedDataMessage(1, 'Первая версия', '', 'moto', '/a', new \DateTimeImmutable()));
        $handler(new ProcessParsedDataMessage(1, 'Вторая версия', '', 'auto', '/a', new \DateTimeImmutable()));

        self::assertCount(1, $this->gameItemRepository()->findAll(), 'Повторный парсинг не должен плодить строки');
        self::assertCount(2, $indexedDocuments, 'Индекс обновляется на каждое сообщение');
        self::assertSame('Вторая версия', $indexedDocuments[1][0]['title']);
    }

    /** @param array<int, array<int, array<string, mixed>>> $indexedDocuments */
    private function createHandler(array &$indexedDocuments): ProcessParsedDataMessageHandler
    {
        $index = $this->createMock(Index::class);
        $index->method('replaceDocuments')->willReturnCallback(
            static function (array $documents) use (&$indexedDocuments): array {
                $indexedDocuments[] = $documents;

                return [];
            },
        );

        $client = $this->createMock(Client::class);
        $client->method('index')->with('game_items')->willReturn($index);

        return new ProcessParsedDataMessageHandler(
            $this->gameItemRepository(),
            new GameItemIndexer($client),
        );
    }
}
