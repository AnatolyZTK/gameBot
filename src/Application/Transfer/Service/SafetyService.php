<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\TransferRepository;

final class SafetyService
{
    public function __construct(
        private TransferRepository $transferRepository,
    ) {
    }

    public function canAccountSend(Account $account, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        if (!$account->isActive()) {
            return false;
        }

        $cooldownUntil = $account->getCooldownUntil();
        if ($cooldownUntil !== null && $cooldownUntil > $now) {
            return false;
        }

        return $this->getRemainingDailySales($account, $now) > 0;
    }

    public function canPairTrade(
        Account $sender,
        Account $receiver,
        ?\DateTimeImmutable $now = null,
    ): bool {
        $now ??= new \DateTimeImmutable();
        $since = $now->sub(new \DateInterval('P1D'));

        return $this->transferRepository->countPairTransfersSince($sender, $receiver, $since) === 0;
    }

    public function applyCooldown(Account $account, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $account->applyCooldown($now->add(new \DateInterval('PT24H')));
    }

    public function getRemainingDailySales(Account $account, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $salesDate = $account->getDailySalesDate();

        if ($salesDate?->format('Y-m-d') !== $today->format('Y-m-d')) {
            return Account::DAILY_SALES_LIMIT;
        }

        return max(0, Account::DAILY_SALES_LIMIT - $account->getDailySalesCount());
    }
}
