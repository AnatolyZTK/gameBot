<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Scraping\Message\ProcessParsedDataMessage;
use App\Tests\Support\DoctrineTestCase;

final class GameItemRepositoryTest extends DoctrineTestCase
{
    public function testUpsertCreatesItemOnFirstScrape(): void
    {
        $repository = $this->gameItemRepository();
        $scrapedAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        $item = $repository->upsertFromMessage(new ProcessParsedDataMessage(
            externalId: 42,
            title: 'Сборка Bandit 400',
            description: 'Описание',
            category: 'moto',
            sourceUrl: '/catalog#item-42',
            scrapedAt: $scrapedAt,
        ));

        self::assertSame(42, $item->getExternalId());
        self::assertSame('Сборка Bandit 400', $item->getTitle());
        self::assertSame($scrapedAt->format('c'), $item->getScrapedAt()->format('c'));
        self::assertCount(1, $repository->findAll());
    }

    public function testUpsertIsIdempotentByExternalId(): void
    {
        $repository = $this->gameItemRepository();

        $first = $repository->upsertFromMessage(new ProcessParsedDataMessage(
            externalId: 42,
            title: 'Старое название',
            description: 'Старое описание',
            category: 'moto',
            sourceUrl: '/catalog#item-42',
            scrapedAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
        ));

        $second = $repository->upsertFromMessage(new ProcessParsedDataMessage(
            externalId: 42,
            title: 'Новое название',
            description: 'Новое описание',
            category: 'auto',
            sourceUrl: '/catalog#item-42-new',
            scrapedAt: new \DateTimeImmutable('2026-01-02 10:00:00'),
        ));

        self::assertTrue($first->getId()->equals($second->getId()), 'Повторный парсинг не должен создавать дубль');
        self::assertCount(1, $repository->findAll());
        self::assertSame('Новое название', $second->getTitle());
        self::assertSame('auto', $second->getCategory());
        self::assertSame('/catalog#item-42-new', $second->getSourceUrl());
    }

    public function testDifferentExternalIdsCreateSeparateItems(): void
    {
        $repository = $this->gameItemRepository();
        $scrapedAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        $repository->upsertFromMessage(new ProcessParsedDataMessage(1, 'A', '', 'c', '/a', $scrapedAt));
        $repository->upsertFromMessage(new ProcessParsedDataMessage(2, 'B', '', 'c', '/b', $scrapedAt));

        self::assertCount(2, $repository->findAll());
    }
}
