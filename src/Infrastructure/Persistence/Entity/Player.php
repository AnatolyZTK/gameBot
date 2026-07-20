<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\Table(name: 'players')]
#[ORM\UniqueConstraint(name: 'uniq_futbin_id', columns: ['futbin_id'])]
#[ORM\Index(name: 'idx_is_favorite', columns: ['is_favorite'])]
#[ORM\Index(name: 'idx_price_updated_at', columns: ['price_updated_at'])]
class Player
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'futbin_id', type: Types::INTEGER)]
    private int $futbinId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rating = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(name: 'price_ps', type: Types::INTEGER, nullable: true)]
    private ?int $pricePs = null;

    #[ORM\Column(name: 'price_xbox', type: Types::INTEGER, nullable: true)]
    private ?int $priceXbox = null;

    #[ORM\Column(name: 'price_pc', type: Types::INTEGER, nullable: true)]
    private ?int $pricePc = null;

    #[ORM\Column(name: 'futbin_url', length: 1024)]
    private string $futbinUrl;

    #[ORM\Column(name: 'is_favorite', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isFavorite = false;

    #[ORM\Column(name: 'price_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $priceUpdatedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $futbinId,
        string $name,
        string $futbinUrl,
    ) {
        $this->id = Uuid::v7();
        $this->futbinId = $futbinId;
        $this->name = $name;
        $this->futbinUrl = $futbinUrl;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFutbinId(): int
    {
        return $this->futbinId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function getPricePs(): ?int
    {
        return $this->pricePs;
    }

    public function getPriceXbox(): ?int
    {
        return $this->priceXbox;
    }

    public function getPricePc(): ?int
    {
        return $this->pricePc;
    }

    public function getFutbinUrl(): string
    {
        return $this->futbinUrl;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function getPriceUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->priceUpdatedAt;
    }

    public function setFavorite(bool $isFavorite): void
    {
        $this->isFavorite = $isFavorite;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatePrices(
        ?int $rating,
        ?string $position,
        ?int $pricePs,
        ?int $priceXbox,
        ?int $pricePc,
        \DateTimeImmutable $updatedAt,
    ): void {
        $this->rating = $rating;
        $this->position = $position;
        $this->pricePs = $pricePs;
        $this->priceXbox = $priceXbox;
        $this->pricePc = $pricePc;
        $this->priceUpdatedAt = $updatedAt;
        $this->updatedAt = $updatedAt;
    }
}
