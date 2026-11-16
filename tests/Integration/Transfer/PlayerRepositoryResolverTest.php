<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfer;

use App\Application\Transfer\Service\PlayerRepositoryResolver;
use App\Infrastructure\Persistence\Entity\Player;
use App\Tests\Support\DoctrineTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class PlayerRepositoryResolverTest extends DoctrineTestCase
{
    private PlayerRepositoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PlayerRepositoryResolver($this->playerRepository(), $this->entityManager);
    }

    public function testCreatesSyntheticPlayerForUnknownName(): void
    {
        $player = $this->resolver->resolveOrCreateByName('  Random Bronze  ');

        self::assertSame('Random Bronze', $player->getName(), 'Имя должно обрезаться по краям');
        self::assertGreaterThanOrEqual(900_000_000, $player->getFutbinId(), 'Синтетические id лежат выше реальных futbin');
        self::assertStringContainsString('Random%20Bronze', $player->getFutbinUrl());
        self::assertCount(1, $this->playerRepository()->findAll());
    }

    public function testReusesExistingPlayerInsteadOfCreatingDuplicate(): void
    {
        $existing = new Player(231747, 'Kylian Mbappé', 'https://www.futbin.com/player/231747');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $resolved = $this->resolver->resolveOrCreateByName('Kylian Mbappé');

        self::assertTrue($existing->getId()->equals($resolved->getId()));
        self::assertSame(231747, $resolved->getFutbinId(), 'Реальный futbinId не должен подменяться синтетическим');
        self::assertCount(1, $this->playerRepository()->findAll());
    }

    public function testSyntheticIdIsStableForSameName(): void
    {
        $first = $this->resolver->resolveOrCreateByName('Bought card');
        $expectedId = $first->getFutbinId();

        $this->entityManager->remove($first);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $second = $this->resolver->resolveOrCreateByName('Bought card');

        self::assertSame($expectedId, $second->getFutbinId());
    }

    /**
     * Найденный тестом дефект: поиск идёт по точному имени, а синтетический
     * futbinId считается от имени в нижнем регистре. Два написания одного имени
     * («Bought card» и «bought card») дают один и тот же futbinId и падают на
     * уникальном индексе players.futbin_id — вместо переиспользования записи.
     *
     * Имена приходят из UI EA (в т.ч. плейсхолдер «Bought card»), так что случай
     * достижим и роняет шаг перевода. Тест фиксирует текущее поведение: когда
     * поиск станет регистронезависимым, он покажет, что фикс сработал.
     */
    public function testNamesDifferingOnlyInCaseCollideOnSyntheticFutbinId(): void
    {
        $lower = $this->resolver->resolveOrCreateByName('bought card');

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $this->resolver->resolveOrCreateByName('BOUGHT CARD');
        } finally {
            self::assertSame(
                $lower->getFutbinId(),
                900_000_000 + (crc32('bought card') % 99_000_000),
            );
        }
    }

    public function testDifferentNamesGetDifferentIds(): void
    {
        $first = $this->resolver->resolveOrCreateByName('Player One');
        $second = $this->resolver->resolveOrCreateByName('Player Two');

        self::assertNotSame($first->getFutbinId(), $second->getFutbinId());
    }
}
