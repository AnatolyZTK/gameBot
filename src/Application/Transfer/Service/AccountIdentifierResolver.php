<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Symfony\Component\Uid\Uuid;

final class AccountIdentifierResolver
{
    public function __construct(
        private AccountRepository $accountRepository,
    ) {
    }

    public function resolve(string $identifier): Account
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('Account identifier is empty.');
        }

        $account = Uuid::isValid($identifier)
            ? $this->accountRepository->find(Uuid::fromString($identifier))
            : $this->accountRepository->findByEmail($identifier);

        if (!$account instanceof Account) {
            throw new \InvalidArgumentException(sprintf('Account not found: %s', $identifier));
        }

        return $account;
    }
}
