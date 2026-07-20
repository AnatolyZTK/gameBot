<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Account> */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findByEmail(string $email): ?Account
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /** @return list<Account> */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true], ['email' => 'ASC']);
    }
}
