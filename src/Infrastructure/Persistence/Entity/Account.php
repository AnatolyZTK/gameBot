<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\UniqueConstraint(name: 'uniq_account_email', columns: ['email'])]
class Account
{
    public const DAILY_SALES_LIMIT = 4;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(name: 'totp_secret', length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'proxy_url', length: 512, nullable: true)]
    private ?string $proxyUrl = null;

    #[ORM\Column(length: 16, enumType: Platform::class)]
    private Platform $platform;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $balance = null;

    #[ORM\Column(name: 'cooldown_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cooldownUntil = null;

    #[ORM\Column(name: 'daily_sales_count', type: Types::SMALLINT, options: ['default' => 0])]
    private int $dailySalesCount = 0;

    #[ORM\Column(name: 'daily_sales_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dailySalesDate = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, Platform $platform)
    {
        $this->id = Uuid::v7();
        $this->email = strtolower(trim($email));
        $this->platform = $platform;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function getProxyUrl(): ?string
    {
        return $this->proxyUrl;
    }

    public function getPlatform(): Platform
    {
        return $this->platform;
    }

    public function getBalance(): ?int
    {
        return $this->balance;
    }

    public function getCooldownUntil(): ?\DateTimeImmutable
    {
        return $this->cooldownUntil;
    }

    public function getDailySalesCount(): int
    {
        return $this->dailySalesCount;
    }

    public function getDailySalesDate(): ?\DateTimeImmutable
    {
        return $this->dailySalesDate;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setCredentials(?string $password, ?string $totpSecret, ?string $proxyUrl): void
    {
        $this->password = $password !== null && $password !== '' ? $password : null;
        $this->totpSecret = $totpSecret !== null && $totpSecret !== '' ? strtoupper(trim($totpSecret)) : null;
        $this->proxyUrl = $proxyUrl !== null && $proxyUrl !== '' ? $proxyUrl : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setBalance(?int $balance): void
    {
        $this->balance = $balance;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setPlatform(Platform $platform): void
    {
        $this->platform = $platform;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function applyCooldown(\DateTimeImmutable $until): void
    {
        $this->cooldownUntil = $until;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function registerSale(\DateTimeImmutable $now): void
    {
        $today = $now->setTime(0, 0);

        if ($this->dailySalesDate?->format('Y-m-d') !== $today->format('Y-m-d')) {
            $this->dailySalesCount = 0;
            $this->dailySalesDate = $today;
        }

        ++$this->dailySalesCount;
        $this->updatedAt = $now;
    }

    public function requirePassword(): string
    {
        if ($this->password === null || $this->password === '') {
            throw new \RuntimeException('У аккаунта '.$this->email.' не задан password.');
        }

        return $this->password;
    }

    public function requireTotpSecret(): string
    {
        if ($this->totpSecret === null || $this->totpSecret === '') {
            throw new \RuntimeException('У аккаунта '.$this->email.' не задан totp_secret.');
        }

        return $this->totpSecret;
    }
}
