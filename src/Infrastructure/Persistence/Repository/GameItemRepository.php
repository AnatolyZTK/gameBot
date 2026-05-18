<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Application\Scraping\Message\ProcessParsedDataMessage;
use App\Infrastructure\Persistence\Entity\GameItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameItem>
 */
final class GameItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameItem::class);
    }

    public function upsertFromMessage(ProcessParsedDataMessage $message): GameItem
    {
        $entity = $this->findOneBy(['externalId' => $message->externalId]);

        if ($entity === null) {
            $entity = new GameItem(
                externalId: $message->externalId,
                title: $message->title,
                description: $message->description,
                category: $message->category,
                sourceUrl: $message->sourceUrl,
                scrapedAt: $message->scrapedAt,
            );
            $this->getEntityManager()->persist($entity);
        } else {
            $entity->updateFromScrape(
                title: $message->title,
                description: $message->description,
                category: $message->category,
                sourceUrl: $message->sourceUrl,
                scrapedAt: $message->scrapedAt,
            );
        }

        $this->getEntityManager()->flush();

        return $entity;
    }
}
