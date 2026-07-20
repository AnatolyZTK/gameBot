<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Transfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Transfer> */
final class TransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    public function countPairTransfersSince(
        Account $sender,
        Account $receiver,
        \DateTimeImmutable $since,
    ): int {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.senderAccount = :sender')
            ->andWhere('t.receiverAccount = :receiver')
            ->andWhere('t.createdAt >= :since')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('sender', $sender)
            ->setParameter('receiver', $receiver)
            ->setParameter('since', $since)
            ->setParameter('statuses', ['executing', 'completed'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Transfer> */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
