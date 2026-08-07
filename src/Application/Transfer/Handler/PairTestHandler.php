<?php

declare(strict_types=1);

namespace App\Application\Transfer\Handler;

use App\Application\Transfer\Message\PairTestMessage;
use App\Application\Transfer\Service\CrossAccountListAndSnipeService;
use App\Application\Transfer\Service\PlayerRepositoryResolver;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class PairTestHandler
{
    public function __construct(
        private AccountRepository $accountRepository,
        private PlayerRepository $playerRepository,
        private PlayerRepositoryResolver $playerResolver,
        private CrossAccountListAndSnipeService $listAndSnipeService,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PairTestMessage $message): void
    {
        $sender = $this->accountRepository->find(Uuid::fromString($message->senderId));
        $receiver = $this->accountRepository->find(Uuid::fromString($message->receiverId));
        if (!$sender instanceof Account || !$receiver instanceof Account) {
            $this->logger->warning('PairTest: account missing', [
                'senderId' => $message->senderId,
                'receiverId' => $message->receiverId,
            ]);

            return;
        }

        $player = null;
        if ($message->playerFutbinId !== null) {
            $player = $this->playerRepository->findByFutbinId($message->playerFutbinId);
        } elseif (is_string($message->playerName) && $message->playerName !== '') {
            $player = $this->playerResolver->resolveOrCreateByName($message->playerName);
        }

        $this->logger->info('PairTest: starting', [
            'sender' => $sender->getEmail(),
            'receiver' => $receiver->getEmail(),
            'listPrice' => $message->listPrice,
        ]);

        $result = $this->listAndSnipeService->listAndSnipe(
            $sender,
            $receiver,
            $player,
            $message->buyMax,
            $message->listPrice,
            $message->buyFirst,
            $message->buyAny,
        );

        $this->logger->info('PairTest: completed', $result);
    }
}
