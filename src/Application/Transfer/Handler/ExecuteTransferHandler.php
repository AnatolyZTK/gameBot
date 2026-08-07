<?php

declare(strict_types=1);

namespace App\Application\Transfer\Handler;

use App\Application\Transfer\Message\ExecuteTransferMessage;
use App\Application\Transfer\Service\CrossAccountListAndSnipeService;
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
        private CrossAccountListAndSnipeService $listAndSnipeService,
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

        $planPreview = $transfer->getPlan() ?? [];
        $firstStep = is_array($planPreview['players'][0] ?? null) ? $planPreview['players'][0] : [];
        $listerIsReceiver = (($firstStep['mode'] ?? '') === 'any')
            && (($firstStep['lister'] ?? 'receiver') === 'receiver');
        $listerForSafety = $listerIsReceiver ? $receiver : $sender;

        if (!$this->safetyService->canAccountSend($listerForSafety) || !$this->safetyService->canPairTrade($sender, $receiver)) {
            $transfer->markFailed('Safety checks failed for sender/receiver pair.');
            $this->entityManager->flush();

            return;
        }

        $transfer->markExecuting();
        $this->entityManager->flush();

        try {
            $lister = $this->executePlan($transfer);
            $this->safetyService->applyCooldown($lister);
            $lister->registerSale(new \DateTimeImmutable());
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

    /**
     * @return \App\Infrastructure\Persistence\Entity\Account аккаунт, который листил (для cooldown)
     */
    private function executePlan(Transfer $transfer): \App\Infrastructure\Persistence\Entity\Account
    {
        $plan = $transfer->getPlan();
        $players = $plan['players'] ?? [];
        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();

        if ($sender === null) {
            throw new \RuntimeException('Sender account is not assigned.');
        }

        if ($players === []) {
            throw new \RuntimeException('Transfer plan has no player steps.');
        }

        $listerAccount = $sender;

        foreach ($players as $playerStep) {
            if (!is_array($playerStep)) {
                continue;
            }

            $mode = (string) ($playerStep['mode'] ?? 'named');
            $listPrice = (int) ($playerStep['listPrice'] ?? 0);
            $buyMax = (int) ($playerStep['buyMax'] ?? $playerStep['buyPrice'] ?? 0);

            if ($listPrice <= 0) {
                throw new \RuntimeException('Invalid listPrice in transfer plan.');
            }

            if ($mode === 'any') {
                // Получатель перевода листит → ему приходят монеты; sender снайпит (платит).
                $lister = (($playerStep['lister'] ?? 'receiver') === 'sender') ? $sender : $receiver;
                $sniper = ($lister === $sender) ? $receiver : $sender;
                $listerAccount = $lister;

                if (!$this->safetyService->canAccountSend($lister)) {
                    throw new \RuntimeException('Lister account failed safety checks (cooldown/sales limit).');
                }

                $this->logger->info('Executing any-card transfer step', [
                    'lister' => $lister->getEmail(),
                    'sniper' => $sniper->getEmail(),
                    'buyMax' => $buyMax,
                    'listPrice' => $listPrice,
                ]);
                $this->listAndSnipeService->listAndSnipe(
                    $lister,
                    $sniper,
                    null,
                    max(150, $buyMax),
                    $listPrice,
                    true,
                    true,
                );

                continue;
            }

            $futbinId = (int) ($playerStep['futbinId'] ?? 0);
            $player = $this->playerRepository->findByFutbinId($futbinId);
            if ($player === null) {
                throw new \RuntimeException(sprintf('Player futbin:%d not found.', $futbinId));
            }

            $buyPrice = (int) ($playerStep['buyPrice'] ?? 0);
            if ($buyPrice <= 0) {
                throw new \RuntimeException('Invalid buyPrice in transfer plan.');
            }

            $listerAccount = $sender;

            if (!$this->sniperService->buyPlayer($sender, $player, $buyPrice)) {
                throw new \RuntimeException(sprintf(
                    'Failed to buy player %s on sender (max %s). Search/UI or no listings.',
                    $player->getName(),
                    number_format($buyPrice, 0, '.', ' '),
                ));
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

        return $listerAccount;
    }
}
