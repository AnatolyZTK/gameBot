<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Entity;

use App\Domain\Transfer\Enum\Platform;
use App\Domain\Transfer\Enum\TransferStatus;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Transfer;
use PHPUnit\Framework\TestCase;

final class TransferTest extends TestCase
{
    public function testNewTransferIsPlannedWithoutSender(): void
    {
        $transfer = new Transfer(new Account('receiver@example.com', Platform::Ps), 250_000);

        self::assertSame(TransferStatus::Planned, $transfer->getStatus());
        self::assertSame(250_000, $transfer->getTargetAmount());
        self::assertNull($transfer->getSenderAccount());
        self::assertNull($transfer->getPlan());
        self::assertNull($transfer->getErrorMessage());
        self::assertNull($transfer->getCompletedAt());
    }

    public function testAssignSenderAndPlan(): void
    {
        $sender = new Account('sender@example.com', Platform::Ps);
        $transfer = new Transfer(new Account('receiver@example.com', Platform::Ps), 1_000);

        $transfer->assignSender($sender);
        $transfer->setPlan(['steps' => [], 'players' => []]);

        self::assertSame($sender, $transfer->getSenderAccount());
        self::assertSame(['steps' => [], 'players' => []], $transfer->getPlan());
    }

    public function testMarkExecutingDoesNotCompleteTransfer(): void
    {
        $transfer = new Transfer(new Account('receiver@example.com', Platform::Ps), 1_000);

        $transfer->markExecuting();

        self::assertSame(TransferStatus::Executing, $transfer->getStatus());
        self::assertNull($transfer->getCompletedAt());
    }

    public function testMarkCompletedSetsCompletedAt(): void
    {
        $transfer = new Transfer(new Account('receiver@example.com', Platform::Ps), 1_000);

        $transfer->markCompleted();

        self::assertSame(TransferStatus::Completed, $transfer->getStatus());
        self::assertNotNull($transfer->getCompletedAt());
        self::assertNull($transfer->getErrorMessage());
    }

    public function testMarkFailedStoresErrorAndCompletedAt(): void
    {
        $transfer = new Transfer(new Account('receiver@example.com', Platform::Ps), 1_000);

        $transfer->markFailed('Safety checks failed');

        self::assertSame(TransferStatus::Failed, $transfer->getStatus());
        self::assertSame('Safety checks failed', $transfer->getErrorMessage());
        self::assertNotNull($transfer->getCompletedAt());
    }
}
