<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Transfer\Enum\TransferStatus;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransferRepository::class)]
#[ORM\Table(name: 'transfers')]
#[ORM\Index(name: 'idx_transfer_status', columns: ['status'])]
#[ORM\Index(name: 'idx_transfer_created_at', columns: ['created_at'])]
class Transfer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'receiver_account_id', nullable: false, onDelete: 'RESTRICT')]
    private Account $receiverAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'sender_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $senderAccount = null;

    #[ORM\Column(name: 'target_amount', type: Types::INTEGER)]
    private int $targetAmount;

    #[ORM\Column(length: 32, enumType: TransferStatus::class)]
    private TransferStatus $status;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $plan = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Account $receiverAccount, int $targetAmount)
    {
        $this->id = Uuid::v7();
        $this->receiverAccount = $receiverAccount;
        $this->targetAmount = $targetAmount;
        $this->status = TransferStatus::Planned;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReceiverAccount(): Account
    {
        return $this->receiverAccount;
    }

    public function getSenderAccount(): ?Account
    {
        return $this->senderAccount;
    }

    public function getTargetAmount(): int
    {
        return $this->targetAmount;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function getPlan(): ?array
    {
        return $this->plan;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function assignSender(Account $senderAccount): void
    {
        $this->senderAccount = $senderAccount;
    }

    /** @param array<string, mixed> $plan */
    public function setPlan(array $plan): void
    {
        $this->plan = $plan;
    }

    public function markExecuting(): void
    {
        $this->status = TransferStatus::Executing;
    }

    public function markCompleted(): void
    {
        $this->status = TransferStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $errorMessage): void
    {
        $this->status = TransferStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();
    }
}
