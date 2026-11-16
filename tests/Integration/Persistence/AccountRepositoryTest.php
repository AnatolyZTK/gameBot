<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use App\Tests\Support\DoctrineTestCase;

final class AccountRepositoryTest extends DoctrineTestCase
{
    public function testFindByEmailNormalisesInput(): void
    {
        $this->persistAccount('player@example.com');
        $repository = $this->accountRepository();

        self::assertNotNull($repository->findByEmail('player@example.com'));
        self::assertNotNull($repository->findByEmail('  PLAYER@Example.COM  '));
        self::assertNull($repository->findByEmail('unknown@example.com'));
    }

    public function testFindActiveSkipsDisabledAccountsAndSortsByEmail(): void
    {
        $this->persistAccount('zeta@example.com');
        $this->persistAccount('alpha@example.com');
        $disabled = $this->persistAccount('beta@example.com');
        $disabled->setActive(false);
        $this->entityManager->flush();

        $active = $this->accountRepository()->findActive();

        self::assertSame(
            ['alpha@example.com', 'zeta@example.com'],
            array_map(static fn (Account $a): string => $a->getEmail(), $active),
        );
    }

    public function testFindAllOrderedIncludesDisabledAccounts(): void
    {
        $this->persistAccount('zeta@example.com');
        $disabled = $this->persistAccount('alpha@example.com');
        $disabled->setActive(false);
        $this->entityManager->flush();

        $all = $this->accountRepository()->findAllOrdered();

        self::assertSame(
            ['alpha@example.com', 'zeta@example.com'],
            array_map(static fn (Account $a): string => $a->getEmail(), $all),
        );
    }

    private function persistAccount(string $email): Account
    {
        $account = new Account($email, Platform::Ps);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
}
