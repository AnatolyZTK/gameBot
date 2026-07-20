<?php

declare(strict_types=1);

namespace App\Application\Transfer\Handler;

use App\Application\Transfer\Message\ExecuteTransferMessage;
use App\Application\Transfer\Service\SafetyService;
use App\Application\Transfer\Service\SniperService;
use App\Domain\Transfer\Enum\TransferStatus;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ExecuteTransferHandler
{
    public function __construct(
        private TransferRepository $transferRepository,
        private PlayerRepository $playerRepository,
        private SniperService $sniperService,
        private SafetyService $safetyService,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteTransferMessage $message): void
    {
        $transfer = $this->transferRepository->find(Uuid::fromString($message->transferId));
        if (!$transfer instanceof Transfer) {
            $this->logger->warning('Transfer not found for execution', ['id' => $message->transferId]);

            return;
        }

        if ($transfer->getStatus() !== TransferStatus::Planned) {
            $this->logger->info('Transfer already processed', ['id' => $message->transferId]);

            return;
        }

        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();
        if ($sender === null) {
            $transfer->markFailed('Sender account is not assigned.');
            $this->entityManager->flush();

            return;
        }

        if (!$this->safetyService->canAccountSend($sender) || !$this->safetyService->canPairTrade($sender, $receiver)) {
            $transfer->markFailed('Safety checks failed for sender/receiver pair.');
            $this->entityManager->flush();

            return;
        }

        $transfer->markExecuting();
        $this->entityManager->flush();

        try {
            $this->executePlan($transfer);
            $this->safetyService->applyCooldown($sender);
            $sender->registerSale(new \DateTimeImmutable());
            $transfer->markCompleted();
        } catch (\Throwable $exception) {
            $transfer->markFailed($exception->getMessage());
            $this->logger->error('Transfer execution failed', [
                'transferId' => $message->transferId,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->entityManager->flush();
    }

    private function executePlan(Transfer $transfer): void
    {
        $plan = $transfer->getPlan();
        $players = $plan['players'] ?? [];
        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();

        if ($sender === null) {
            throw new \RuntimeException('Sender account is not assigned.');
        }

        foreach ($players as $playerStep) {
            if (!is_array($playerStep)) {
                continue;
            }

            $futbinId = (int) ($playerStep['futbinId'] ?? 0);
            $player = $this->playerRepository->findByFutbinId($futbinId);
            if ($player === null) {
                throw new \RuntimeException(sprintf('Player futbin:%d not found.', $futbinId));
            }

            $buyPrice = (int) ($playerStep['buyPrice'] ?? 0);
            $listPrice = (int) ($playerStep['listPrice'] ?? 0);

            if (!$this->sniperService->buyPlayer($sender, $player, $buyPrice)) {
                throw new \RuntimeException(sprintf('Failed to buy player %s on sender.', $player->getName()));
            }

            if (!$this->sniperService->sellForMinPrice($sender, $player, $listPrice)) {
                throw new \RuntimeException(sprintf('Failed to list player %s on sender.', $player->getName()));
            }

            if (!$this->sniperService->snipePlayer($receiver, $player, $listPrice)) {
                throw new \RuntimeException(sprintf('Failed to snipe player %s on receiver.', $player->getName()));
            }

            if (!$this->sniperService->sellForMarketPrice($receiver, $player)) {
                throw new \RuntimeException(sprintf('Failed to sell player %s on receiver.', $player->getName()));
            }
        }
    }
}
