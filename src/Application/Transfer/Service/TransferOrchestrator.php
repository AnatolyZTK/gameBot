<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class TransferOrchestrator
{
    public function __construct(
        private AccountRepository $accountRepository,
        private PlayerRepository $playerRepository,
        private TransferRepository $transferRepository,
        private SafetyService $safetyService,
        private MathService $mathService,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return array{
     *   receiverId: string,
     *   targetAmount: int,
     *   steps: list<array<string, mixed>>,
     *   senders: list<array<string, mixed>>,
     *   estimatedProfit: int
     * }
     */
    public function planTransfer(Uuid $receiverId, int $amount): array
    {
        $receiver = $this->accountRepository->find($receiverId);
        if (!$receiver instanceof Account) {
            throw new \InvalidArgumentException('Receiver account not found.');
        }

        $amountSteps = $this->mathService->calculateRequiredAmounts($amount);
        $favoritePlayers = $this->playerRepository->findFavorites();
        $now = new \DateTimeImmutable();
        $senders = $this->resolveAvailableSenders($receiver, $now);

        $playerPlan = $this->buildPlayerPlan($favoritePlayers, $receiver->getPlatform(), $amountSteps);

        return [
            'receiverId' => $receiver->getId()->toRfc4122(),
            'targetAmount' => $amount,
            'steps' => $amountSteps,
            'senders' => array_map(function (Account $account) use ($now): array {
                return [
                    'id' => $account->getId()->toRfc4122(),
                    'email' => $account->getEmail(),
                    'remainingSales' => $this->safetyService->getRemainingDailySales($account, $now),
                    'canSend' => $this->safetyService->canAccountSend($account, $now),
                ];
            }, $senders),
            'players' => $playerPlan,
            'estimatedProfit' => array_sum(array_map(
                static fn (array $player): int => (int) ($player['estimatedProfit'] ?? 0),
                $playerPlan,
            )),
        ];
    }

    public function executeTransfer(Uuid $transferId): void
    {
        $transfer = $this->transferRepository->find($transferId);
        if (!$transfer instanceof Transfer) {
            throw new \InvalidArgumentException('Transfer not found.');
        }

        $this->messageBus->dispatch(new \App\Application\Transfer\Message\ExecuteTransferMessage(
            transferId: $transfer->getId()->toRfc4122(),
        ));
    }

    public function createAndQueueTransfer(Uuid $receiverId, int $amount, ?Uuid $senderId = null): Transfer
    {
        $receiver = $this->accountRepository->find($receiverId);
        if (!$receiver instanceof Account) {
            throw new \InvalidArgumentException('Receiver account not found.');
        }

        $plan = $this->planTransfer($receiverId, $amount);
        $transfer = new Transfer($receiver, $amount);
        $transfer->setPlan($plan);

        $senders = $this->resolveAvailableSenders($receiver);
        if ($senderId !== null) {
            $sender = $this->accountRepository->find($senderId);
            if (!$sender instanceof Account) {
                throw new \InvalidArgumentException('Sender account not found.');
            }

            if ($sender->getId()->toRfc4122() === $receiver->getId()->toRfc4122()) {
                throw new \InvalidArgumentException('Sender and receiver must be different accounts.');
            }

            $now = new \DateTimeImmutable();
            if (!$this->safetyService->canAccountSend($sender, $now) || !$this->safetyService->canPairTrade($sender, $receiver, $now)) {
                throw new \InvalidArgumentException('Selected sender failed safety checks.');
            }

            $transfer->assignSender($sender);
        } elseif ($senders !== []) {
            $transfer->assignSender($senders[0]);
        }

        $this->entityManager->persist($transfer);
        $this->entityManager->flush();

        $this->executeTransfer($transfer->getId());

        return $transfer;
    }

    /**
     * @param list<array{step: int, listPrice: int, netProceeds: int}> $amountSteps
     * @param list<Player> $players
     *
     * @return list<array<string, mixed>>
     */
    private function buildPlayerPlan(array $players, Platform $platform, array $amountSteps): array
    {
        $plan = [];
        foreach ($amountSteps as $index => $step) {
            $listPrice = (int) $step['listPrice'];

            // Надёжный bridge как pair-test: дешёвая покупка → листинг на сумму перевода → снайп.
            // Named flip (Mbappé и т.п.) слишком хрупкий для UI-поиска и разоряет sender.
            $buyMax = min(12_000, max(2_000, (int) floor($listPrice * 0.08)));
            $plan[] = [
                'mode' => 'any',
                'futbinId' => null,
                'name' => 'any ≤ '.$buyMax,
                'buyPrice' => $buyMax,
                'buyMax' => $buyMax,
                'listPrice' => $listPrice,
                'expectedNet' => $step['netProceeds'],
                'estimatedProfit' => 0,
                // Кто листит получает net; для перевода «на receiver» листит receiver.
                'lister' => 'receiver',
                'sniper' => 'sender',
            ];
        }

        return $plan;
    }

    /** @return list<Account> */
    private function resolveAvailableSenders(Account $receiver, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return array_values(array_filter(
            $this->accountRepository->findActive(),
            fn (Account $account): bool => $account->getId()->toRfc4122() !== $receiver->getId()->toRfc4122()
                && $this->safetyService->canAccountSend($account, $now)
                && $this->safetyService->canPairTrade($account, $receiver, $now),
        ));
    }

    private function resolvePlayerPrice(Player $player, Platform $platform): ?int
    {
        return match ($platform) {
            Platform::Ps => $player->getPricePs(),
            Platform::Xbox => $player->getPriceXbox(),
            Platform::Pc => $player->getPricePc(),
        };
    }
}
