<?php

declare(strict_types=1);

namespace App\Application\Transfer\Handler;

use App\Application\Transfer\Message\LoginAccountMessage;
use App\Infrastructure\Browser\EaFutLoginService;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class LoginAccountHandler
{
    public function __construct(
        private AccountRepository $accountRepository,
        private EaFutLoginService $loginService,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginAccountMessage $message): void
    {
        $account = $this->accountRepository->find(Uuid::fromString($message->accountId));
        if (!$account instanceof Account) {
            $this->logger->warning('LoginAccount: account not found', ['id' => $message->accountId]);

            return;
        }

        $this->logger->info('LoginAccount: starting', ['email' => $account->getEmail()]);
        $this->loginService->ensureLoggedIn($account);
        $this->logger->info('LoginAccount: done', ['email' => $account->getEmail()]);
    }
}
