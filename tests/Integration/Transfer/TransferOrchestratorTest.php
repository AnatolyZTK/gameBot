<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfer;

use App\Application\Transfer\Message\ExecuteTransferMessage;
use App\Application\Transfer\Service\MathService;
use App\Application\Transfer\Service\SafetyService;
use App\Application\Transfer\Service\TransferOrchestrator;
use App\Domain\Transfer\Enum\Platform;
use App\Domain\Transfer\Enum\TransferStatus;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Tests\Support\CollectingMessageBus;
use App\Tests\Support\DoctrineTestCase;
use Symfony\Component\Uid\Uuid;

final class TransferOrchestratorTest extends DoctrineTestCase
{
    private TransferOrchestrator $orchestrator;

    private CollectingMessageBus $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new CollectingMessageBus();
        $this->orchestrator = new TransferOrchestrator(
            $this->accountRepository(),
            $this->playerRepository(),
            $this->transferRepository(),
            new SafetyService($this->transferRepository()),
            new MathService(),
            $this->entityManager,
            $this->bus,
        );
    }

    public function testPlanSplitsAmountIntoValidBinSteps(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        $plan = $this->orchestrator->planTransfer($receiver->getId(), 600_000);

        self::assertSame($receiver->getId()->toRfc4122(), $plan['receiverId']);
        self::assertSame(600_000, $plan['targetAmount']);
        self::assertCount(2, $plan['steps'], 'Сумма выше 500k должна разбиваться на шаги');
        self::assertGreaterThanOrEqual(600_000, array_sum(array_column($plan['steps'], 'netProceeds')));
    }

    public function testPlanBuildsOneAnyCardStepPerAmountStep(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        $plan = $this->orchestrator->planTransfer($receiver->getId(), 100_000);

        self::assertCount(\count($plan['steps']), $plan['players']);

        foreach ($plan['players'] as $index => $step) {
            self::assertSame('any', $step['mode'], 'Планировщик умеет только режим any');
            self::assertNull($step['futbinId']);
            self::assertSame('receiver', $step['lister'], 'Монеты получает тот, кто листит');
            self::assertSame('sender', $step['sniper']);
            self::assertSame($plan['steps'][$index]['listPrice'], $step['listPrice']);
            self::assertSame($plan['steps'][$index]['netProceeds'], $step['expectedNet']);
        }
    }

    public function testAnyCardBuyBudgetStaysInsideTwoToTwelveThousand(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        foreach ([1_000, 50_000, 300_000, 5_000_000] as $amount) {
            $plan = $this->orchestrator->planTransfer($receiver->getId(), $amount);

            foreach ($plan['players'] as $step) {
                self::assertGreaterThanOrEqual(2_000, $step['buyMax'], 'Сумма '.$amount);
                self::assertLessThanOrEqual(12_000, $step['buyMax'], 'Сумма '.$amount);
                self::assertSame($step['buyMax'], $step['buyPrice']);
                self::assertStringContainsString((string) $step['buyMax'], $step['name']);
            }
        }
    }

    public function testPlanListsOnlyEligibleSenders(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistAccount('ready@example.com');
        $exhausted = $this->persistAccount('exhausted@example.com');
        for ($i = 0; $i < Account::DAILY_SALES_LIMIT; ++$i) {
            $exhausted->registerSale(new \DateTimeImmutable());
        }
        $disabled = $this->persistAccount('disabled@example.com');
        $disabled->setActive(false);
        $this->entityManager->flush();

        $plan = $this->orchestrator->planTransfer($receiver->getId(), 10_000);

        self::assertSame(
            ['ready@example.com'],
            array_column($plan['senders'], 'email'),
            'Получатель, отключённые и исчерпавшие лимит аккаунты не должны попадать в senders',
        );
        self::assertTrue($plan['senders'][0]['canSend']);
        self::assertSame(Account::DAILY_SALES_LIMIT, $plan['senders'][0]['remainingSales']);
    }

    public function testEstimatedProfitIsZeroForAnyCardPlan(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        $plan = $this->orchestrator->planTransfer($receiver->getId(), 100_000);

        self::assertSame(0, $plan['estimatedProfit']);
    }

    public function testPlanFailsForUnknownReceiver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Receiver account not found.');

        $this->orchestrator->planTransfer(Uuid::v7(), 10_000);
    }

    public function testCreateAndQueuePersistsTransferAndDispatchesMessage(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $sender = $this->persistAccount('sender@example.com');

        $transfer = $this->orchestrator->createAndQueueTransfer($receiver->getId(), 100_000);

        self::assertSame(TransferStatus::Planned, $transfer->getStatus());
        self::assertSame($receiver->getId()->toRfc4122(), $transfer->getReceiverAccount()->getId()->toRfc4122());
        self::assertSame($sender->getId()->toRfc4122(), $transfer->getSenderAccount()?->getId()->toRfc4122());
        self::assertNotNull($transfer->getPlan());

        self::assertCount(1, $this->bus->messages);
        $message = $this->bus->messages[0];
        self::assertInstanceOf(ExecuteTransferMessage::class, $message);
        self::assertSame($transfer->getId()->toRfc4122(), $message->transferId);

        $this->entityManager->clear();
        self::assertNotNull($this->transferRepository()->find($transfer->getId()));
    }

    public function testCreateAndQueueUsesExplicitSender(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $this->persistAccount('aaa-default@example.com');
        $chosen = $this->persistAccount('zzz-chosen@example.com');

        $transfer = $this->orchestrator->createAndQueueTransfer($receiver->getId(), 10_000, $chosen->getId());

        self::assertSame($chosen->getId()->toRfc4122(), $transfer->getSenderAccount()?->getId()->toRfc4122());
    }

    public function testCreateAndQueueRejectsSenderEqualToReceiver(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sender and receiver must be different accounts.');

        $this->orchestrator->createAndQueueTransfer($receiver->getId(), 10_000, $receiver->getId());
    }

    public function testCreateAndQueueRejectsUnknownSender(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sender account not found.');

        $this->orchestrator->createAndQueueTransfer($receiver->getId(), 10_000, Uuid::v7());
    }

    public function testCreateAndQueueRejectsSenderThatFailsSafetyChecks(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $blocked = $this->persistAccount('blocked@example.com');
        $blocked->applyCooldown((new \DateTimeImmutable())->add(new \DateInterval('PT1H')));
        $this->entityManager->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected sender failed safety checks.');

        $this->orchestrator->createAndQueueTransfer($receiver->getId(), 10_000, $blocked->getId());
    }

    public function testCreateAndQueueLeavesSenderUnassignedWhenNoneAvailable(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');

        $transfer = $this->orchestrator->createAndQueueTransfer($receiver->getId(), 10_000);

        self::assertNull($transfer->getSenderAccount());
        self::assertCount(1, $this->bus->messages, 'Сообщение всё равно уходит — падать будет уже handler');
    }

    public function testExecuteTransferDispatchesMessageForExistingTransfer(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $transfer = new Transfer($receiver, 10_000);
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();

        $this->orchestrator->executeTransfer($transfer->getId());

        self::assertCount(1, $this->bus->messages);
        self::assertSame($transfer->getId()->toRfc4122(), $this->bus->messages[0]->transferId);
    }

    public function testExecuteTransferFailsForUnknownTransfer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transfer not found.');

        $this->orchestrator->executeTransfer(Uuid::v7());
    }

    public function testFavouritePlayersDoNotAffectAnyCardPlan(): void
    {
        $receiver = $this->persistAccount('receiver@example.com');
        $player = new Player(231747, 'Kylian Mbappé', 'https://www.futbin.com/player/231747');
        $player->setFavorite(true);
        $player->updatePrices(91, 'ST', 10_900_000, 10_750_000, 11_000_000, new \DateTimeImmutable());
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        $plan = $this->orchestrator->planTransfer($receiver->getId(), 50_000);

        foreach ($plan['players'] as $step) {
            self::assertSame('any', $step['mode']);
            self::assertNull($step['futbinId']);
        }
    }

    private function persistAccount(string $email): Account
    {
        $account = new Account($email, Platform::Ps);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
}
