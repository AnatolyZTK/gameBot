<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Player> */
final class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    public function findByFutbinId(int $futbinId): ?Player
    {
        return $this->findOneBy(['futbinId' => $futbinId]);
    }

    /** @return list<Player> */
    public function findFavorites(): array
    {
        return $this->findBy(['isFavorite' => true], ['name' => 'ASC']);
    }
}
