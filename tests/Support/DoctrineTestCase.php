<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Persistence\Repository\AccountRepository;
use App\Infrastructure\Persistence\Repository\GameItemRepository;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * База для тестов, которым нужны настоящие Doctrine-репозитории: они final,
 * замокать их нельзя, поэтому нужен живой EntityManager.
 *
 * Kernel не поднимается, БД не требуется: EntityManager собирается вручную
 * поверх SQLite in-memory, схема создаётся из атрибутов энтити (не из
 * migrations/ — те MySQL-специфичны). UUID подменяется на CHAR(36),
 * см. StringUuidType.
 */
abstract class DoctrineTestCase extends TestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        if (Type::hasType(UuidType::NAME)) {
            Type::overrideType(UuidType::NAME, StringUuidType::class);
        } else {
            Type::addType(UuidType::NAME, StringUuidType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [\dirname(__DIR__, 2).'/src/Infrastructure/Persistence/Entity'],
            isDevMode: true,
        );
        $config->setProxyDir(sys_get_temp_dir().'/doctrine-test-proxies');
        $config->setProxyNamespace('DoctrineTestProxies');
        $config->setAutoGenerateProxyClasses(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $config);

        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            return;
        }

        $this->entityManager->clear();
        $this->entityManager->getConnection()->close();
    }

    protected function accountRepository(): AccountRepository
    {
        return new AccountRepository($this->managerRegistry());
    }

    protected function playerRepository(): PlayerRepository
    {
        return new PlayerRepository($this->managerRegistry());
    }

    protected function transferRepository(): TransferRepository
    {
        return new TransferRepository($this->managerRegistry());
    }

    protected function gameItemRepository(): GameItemRepository
    {
        return new GameItemRepository($this->managerRegistry());
    }

    protected function managerRegistry(): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        return $registry;
    }
}
