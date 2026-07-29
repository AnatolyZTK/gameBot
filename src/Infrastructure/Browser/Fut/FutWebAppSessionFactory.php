<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser\Fut;

use App\Infrastructure\Browser\BrowserProfileSnapshotStorage;
use App\Infrastructure\Browser\StealthChromeClientFactory;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\AccountRepository;

final class FutWebAppSessionFactory
{
    public function __construct(
        private readonly StealthChromeClientFactory $browserFactory,
        private readonly BrowserProfileSnapshotStorage $profileStorage,
        private readonly AccountRepository $accountRepository,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(FutWebAppSession): T $callback
     *
     * @return T
     */
    public function withAccount(Account $account, callable $callback): mixed
    {
        $session = FutWebAppSession::open($this->browserFactory, $this->profileStorage, $account);

        try {
            return $callback($session);
        } finally {
            $session->close();
        }
    }

    /**
     * @template T
     *
     * @param callable(FutWebAppSession): T $callback
     *
     * @return T
     */
    public function withSession(string $email, callable $callback): mixed
    {
        $account = $this->accountRepository->findByEmail($email);
        if (!$account instanceof Account) {
            throw new \RuntimeException(
                'Аккаунт '.$email.' не найден в БД. Добавьте: php bin/console app:account:add --email='.$email
            );
        }

        return $this->withAccount($account, $callback);
    }
}
