<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PlayerRepositoryResolver
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveOrCreateByName(string $name): Player
    {
        $name = trim($name);
        $existing = $this->playerRepository->findOneBy(['name' => $name]);
        if ($existing instanceof Player) {
            return $existing;
        }

        $player = new Player(
            futbinId: 900_000_000 + (crc32(strtolower($name)) % 99_000_000),
            name: $name,
            futbinUrl: 'https://www.futbin.com/player/'.rawurlencode($name),
        );
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
