<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfer;

use App\Application\Transfer\Service\SafetyService;
use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Tests\Support\DoctrineTestCase;

final class SafetyServiceTest extends DoctrineTestCase
{
    private SafetyService $safetyService;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->safetyService = new SafetyService($this->transferRepository());
        // Transfer::createdAt выставляется конструктором по системным часам,
        // поэтому «сейчас» в тестах — тоже реальное время, а не фиксированная дата.
        $this->now = new \DateTimeImmutable();
    }

    public function testFreshAccountCanSend(): void
    {
        $account = $this->persistAccount('sender@example.com');

        self::assertTrue($this->safetyService->canAccountSend($account, $this->now));
        self::assertSame(Account::DAILY_SALES_LIMIT, $this->safetyService->getRemainingDailySales($account, $this->now));
    }

    public function testInactiveAccountCannotSend(): void
    {
        $account = $this->persistAccount('sender@example.com');
        $account->setActive(false);

        self::assertFalse($this->safetyService->canAccountSend($account, $this->now));
    }

    public function testAccountInCooldownCannotSend(): void
    {
        $account = $this->persistAccount('sender@example.com');
        $account->applyCooldown($this->now->add(new \DateInterval('PT1H')));

        self::assertFalse($this->safetyService->canAccountSend($account, $this->now));
    }

    public function testExpiredCooldownDoesNotBlockSending(): void
    {
        $account = $this->persistAccount('sender@example.com');
        $account->applyCooldown($this->now->sub(new \DateInterval('PT1M')));

        self::assertTrue($this->safetyService->canAccountSend($account, $this->now));
    }

    public function testRemainingSalesDecreaseWithEachSale(): void
    {
        $account = $this->persistAccount('sender@example.com');

        for ($i = 1; $i <= Account::DAILY_SALES_LIMIT; ++$i) {
            $account->registerSale($this->now);
            self::assertSame(
                Account::DAILY_SALES_LIMIT - $i,
                $this->safetyService->getRemainingDailySales($account, $this->now),
            );
        }

        self::assertFalse(
            $this->safetyService->canAccountSend($account, $this->now),
            'После исчерпания дневного лимита продаж аккаунт не должен отправлять',
        );
    }

    public function testDailySalesLimitResetsNextDay(): void
    {
        $account = $this->persistAccount('sender@example.com');
        for ($i = 0; $i < Account::DAILY_SALES_LIMIT; ++$i) {
            $account->registerSale($this->now);
        }

        $tomorrow = $this->now->add(new \DateInterval('P1D'));

        self::assertSame(Account::DAILY_SALES_LIMIT, $this->safetyService->getRemainingDailySales($account, $tomorrow));
        self::assertTrue($this->safetyService->canAccountSend($account, $tomorrow));
    }

    public function testApplyCooldownBlocksAccountForTwentyFourHours(): void
    {
        $account = $this->persistAccount('sender@example.com');

        $this->safetyService->applyCooldown($account, $this->now);

        self::assertSame(
            $this->now->add(new \DateInterval('PT24H'))->format('c'),
            $account->getCooldownUntil()?->format('c'),
        );
        self::assertFalse($this->safetyService->canAccountSend($account, $this->now));
        self::assertTrue($this->safetyService->canAccountSend(
            $account,
            $this->now->add(new \DateInterval('PT24H1M')),
        ));
    }

    public function testPairTradeIsAllowedWhenNoRecentTransfers(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');

        self::assertTrue($this->safetyService->canPairTrade($sender, $receiver, $this->now));
    }

    public function testPairTradeIsBlockedByCompletedTransferWithinLastDay(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markCompleted());

        self::assertFalse($this->safetyService->canPairTrade($sender, $receiver, $this->now));
    }

    public function testPairTradeIsBlockedByTransferStillExecuting(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markExecuting());

        self::assertFalse($this->safetyService->canPairTrade($sender, $receiver, $this->now));
    }

    public function testPlannedAndFailedTransfersDoNotBlockPair(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistTransfer($sender, $receiver, null);
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markFailed('нет лотов'));

        self::assertTrue(
            $this->safetyService->canPairTrade($sender, $receiver, $this->now),
            'Незапущенные и упавшие переводы не должны занимать пару на сутки',
        );
    }

    public function testPairTradeIsAllowedAgainAfterTwentyFourHours(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markCompleted());

        // Перевод создан «сейчас», проверяем спустя сутки с запасом
        $later = $this->now->add(new \DateInterval('P1DT5M'));

        self::assertTrue($this->safetyService->canPairTrade($sender, $receiver, $later));
    }

    public function testPairDirectionMatters(): void
    {
        $sender = $this->persistAccount('sender@example.com');
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistTransfer($sender, $receiver, static fn (Transfer $t) => $t->markCompleted());

        self::assertFalse($this->safetyService->canPairTrade($sender, $receiver, $this->now));
        self::assertTrue(
            $this->safetyService->canPairTrade($receiver, $sender, $this->now),
            'Лимит считается по направлению sender→receiver',
        );
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
        $transfer = new Transfer($receiver, 100_000);
        $transfer->assignSender($sender);
        if ($mutate !== null) {
            $mutate($transfer);
        }

        $this->entityManager->persist($transfer);
        $this->entityManager->flush();

        return $transfer;
    }
}
