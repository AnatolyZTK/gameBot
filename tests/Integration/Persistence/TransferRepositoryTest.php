<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Tests\Support\DoctrineTestCase;

final class TransferRepositoryTest extends DoctrineTestCase
{
    public function testFindRecentReturnsNewestFirstAndRespectsLimit(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        for ($i = 1; $i <= 3; ++$i) {
            $transfer = new Transfer($receiver, $i * 1_000);
            $this->entityManager->persist($transfer);
            $this->entityManager->flush();
        }

        $recent = $this->transferRepository()->findRecent(2);

        self::assertCount(2, $recent);
        self::assertGreaterThanOrEqual(
            $recent[1]->getCreatedAt()->getTimestamp(),
            $recent[0]->getCreatedAt()->getTimestamp(),
        );
    }

    public function testFindRecentTreatsNonPositiveLimitAsOne(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $this->entityManager->persist(new Transfer($receiver, 1_000));
        $this->entityManager->persist(new Transfer($receiver, 2_000));
        $this->entityManager->flush();

        self::assertCount(1, $this->transferRepository()->findRecent(0));
        self::assertCount(1, $this->transferRepository()->findRecent(-5));
    }

    public function testCountPairTransfersCountsOnlyExecutingAndCompleted(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $since = (new \DateTimeImmutable())->sub(new \DateInterval('P1D'));

        $this->persistTransfer($sender, $receiver, null);                                   // planned
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markFailed('x'));
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markExecuting());
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markCompleted());

        self::assertSame(
            2,
            $this->transferRepository()->countPairTransfersSince($sender, $receiver, $since),
        );
    }

    public function testCountPairTransfersIgnoresOlderTransfers(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markCompleted());

        $since = (new \DateTimeImmutable())->add(new \DateInterval('PT5M'));

        self::assertSame(
            0,
            $this->transferRepository()->countPairTransfersSince($sender, $receiver, $since),
        );
    }

    public function testCountPairTransfersIgnoresOtherPairs(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $other = $this->persistAccount('other@example.com');
        $since = (new \DateTimeImmutable())->sub(new \DateInterval('P1D'));

        $this->persistTransfer($sender, $other, static fn (Transfer $t) => $t->markCompleted());

        self::assertSame(
            0,
            $this->transferRepository()->countPairTransfersSince($sender, $receiver, $since),
        );
    }

    public function testPlanIsStoredAsJsonAndReadBack(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $transfer = new Transfer($receiver, 100_000);
        $plan = [
            'targetAmount' => 100_000,
            'players' => [['mode' => 'any', 'listPrice' => 105_500, 'lister' => 'receiver']],
        ];
        $transfer->setPlan($plan);
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->transferRepository()->find($transfer->getId());

        self::assertNotNull($reloaded);
        self::assertSame($plan, $reloaded->getPlan());
    }

    private function persistAccount(string $email): Account
    {
        $account = new Account($email, Platform::Ps);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function persistTransfer(Account $sender, Account $receiver, ?callable $mutate): Transfer
    {
        $transfer = new Transfer($receiver, 50_000);
        $transfer->assignSender($sender);
        if ($mutate !== null) {
            $mutate($transfer);
        }
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();

        return $transfer;
    }
}
