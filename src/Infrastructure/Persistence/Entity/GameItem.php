<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Repository\GameItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GameItemRepository::class)]
#[ORM\Table(name: 'game_items')]
#[ORM\UniqueConstraint(name: 'uniq_external_id', columns: ['external_id'])]
#[ORM\Index(name: 'idx_category', columns: ['category'])]
#[ORM\Index(name: 'idx_scraped_at', columns: ['scraped_at'])]
class GameItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'external_id', type: Types::INTEGER)]
    private int $externalId;

    #[ORM\Column(length: 512)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 128)]
    private string $category;

    #[ORM\Column(name: 'source_url', length: 1024)]
    private string $sourceUrl;

    #[ORM\Column(name: 'screenshot_path', length: 512, nullable: true)]
    private ?string $screenshotPath = null;

    #[ORM\Column(name: 'scraped_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scrapedAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $externalId,
        string $title,
        string $description,
        string $category,
        string $sourceUrl,
        \DateTimeImmutable $scrapedAt,
    ) {
        $this->id = Uuid::v7();
        $this->externalId = $externalId;
        $this->title = $title;
        $this->description = $description;
        $this->category = $category;
        $this->sourceUrl = $sourceUrl;
        $this->scrapedAt = $scrapedAt;
        $this->updatedAt = $scrapedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getExternalId(): int
    {
        return $this->externalId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function getScreenshotPath(): ?string
    {
        return $this->screenshotPath;
    }

    public function getScrapedAt(): \DateTimeImmutable
    {
        return $this->scrapedAt;
    }

    public function updateFromScrape(
        string $title,
        string $description,
        string $category,
        string $sourceUrl,
        \DateTimeImmutable $scrapedAt,
    ): void {
        $this->title = $title;
        $this->description = $description;
        $this->category = $category;
        $this->sourceUrl = $sourceUrl;
        $this->scrapedAt = $scrapedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setScreenshotPath(?string $path): void
    {
        $this->screenshotPath = $path;
    }
}
