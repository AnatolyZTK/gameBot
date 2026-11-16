<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Entity\Player;
use App\Tests\Support\DoctrineTestCase;

final class PlayerRepositoryTest extends DoctrineTestCase
{
    public function testFindByFutbinId(): void
    {
        $this->persistPlayer(231747, 'Kylian Mbappé');
        $repository = $this->playerRepository();

        self::assertSame('Kylian Mbappé', $repository->findByFutbinId(231747)?->getName());
        self::assertNull($repository->findByFutbinId(999999));
    }

    public function testFindFavoritesReturnsOnlyFavouritesSortedByName(): void
    {
        $this->persistPlayer(1, 'Zidane')->setFavorite(true);
        $this->persistPlayer(2, 'Ancelotti');
        $this->persistPlayer(3, 'Bellingham')->setFavorite(true);
        $this->entityManager->flush();

        $favorites = $this->playerRepository()->findFavorites();

        self::assertSame(
            ['Bellingham', 'Zidane'],
            array_map(static fn (Player $p): string => $p->getName(), $favorites),
        );
    }

    public function testFindAllOrderedPutsFavouritesFirst(): void
    {
        $this->persistPlayer(1, 'Ancelotti');
        $this->persistPlayer(2, 'Zidane')->setFavorite(true);
        $this->persistPlayer(3, 'Bellingham');
        $this->entityManager->flush();

        $players = $this->playerRepository()->findAllOrdered();

        self::assertSame(
            ['Zidane', 'Ancelotti', 'Bellingham'],
            array_map(static fn (Player $p): string => $p->getName(), $players),
        );
    }

    public function testUpdatePricesStoresQuotePerPlatform(): void
    {
        $player = $this->persistPlayer(231747, 'Kylian Mbappé');
        $updatedAt = new \DateTimeImmutable('2026-03-01 08:00:00');

        $player->updatePrices(91, 'ST', 10_900_000, 10_750_000, 11_000_000, $updatedAt);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->playerRepository()->findByFutbinId(231747);

        self::assertNotNull($reloaded);
        self::assertSame(91, $reloaded->getRating());
        self::assertSame('ST', $reloaded->getPosition());
        self::assertSame(10_900_000, $reloaded->getPricePs());
        self::assertSame(10_750_000, $reloaded->getPriceXbox());
        self::assertSame(11_000_000, $reloaded->getPricePc());
        self::assertSame($updatedAt->format('c'), $reloaded->getPriceUpdatedAt()?->format('c'));
    }

    private function persistPlayer(int $futbinId, string $name): Player
    {
        $player = new Player($futbinId, $name, 'https://www.futbin.com/player/'.$futbinId);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
