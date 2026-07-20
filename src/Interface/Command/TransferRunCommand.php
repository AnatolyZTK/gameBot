<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Transfer\Handler\ExecuteTransferHandler;
use App\Application\Transfer\Message\ExecuteTransferMessage;
use App\Application\Transfer\Service\AccountIdentifierResolver;
use App\Application\Transfer\Service\TransferOrchestrator;
use App\Domain\Transfer\Enum\TransferStatus;
use App\Infrastructure\Persistence\Entity\Transfer;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use App\Interface\Command\Helper\TransferPlanRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transfer:run',
    description: 'Создать и запустить перевод монет',
)]
final class TransferRunCommand extends Command
{
    public function __construct(
        private AccountIdentifierResolver $accountResolver,
        private TransferOrchestrator $orchestrator,
        private TransferPlanRenderer $planRenderer,
        private TransferRepository $transferRepository,
        private ExecuteTransferHandler $executeTransferHandler,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('receiver', 'r', InputOption::VALUE_REQUIRED, 'Email или UUID получателя')
            ->addOption('amount', 'a', InputOption::VALUE_REQUIRED, 'Сумма перевода (coins)')
            ->addOption('sender', 's', InputOption::VALUE_REQUIRED, 'Email или UUID отправителя (опционально)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Выполнить сразу в этом процессе (без очереди)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Не спрашивать подтверждение');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $receiverIdentifier = $input->getOption('receiver');
        if (!is_string($receiverIdentifier) || trim($receiverIdentifier) === '') {
            $io->error('Укажите --receiver (email или UUID)');

            return Command::FAILURE;
        }

        $amountRaw = $input->getOption('amount');
        if (!is_string($amountRaw) || !ctype_digit($amountRaw) || (int) $amountRaw <= 0) {
            $io->error('Укажите --amount (целое число > 0)');

            return Command::FAILURE;
        }

        $amount = (int) $amountRaw;

        try {
            $receiver = $this->accountResolver->resolve($receiverIdentifier);
            $senderId = null;
            $senderLabel = 'auto';

            $senderOption = $input->getOption('sender');
            if (is_string($senderOption) && trim($senderOption) !== '') {
                $sender = $this->accountResolver->resolve($senderOption);
                $senderId = $sender->getId();
                $senderLabel = $sender->getEmail();
            }

            $plan = $this->orchestrator->planTransfer($receiver->getId(), $amount);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->planRenderer->render(
            $io,
            $plan,
            sprintf('%s (%s)', $receiver->getEmail(), $receiver->getPlatform()->label()),
        );

        if (($plan['players'] ?? []) === []) {
            $io->error('План пуст — перевод не может быть выполнен.');

            return Command::FAILURE;
        }

        if (($plan['senders'] ?? []) === [] && $senderId === null) {
            $io->error('Нет доступных отправителей. Укажите --sender или дождитесь снятия cooldown.');

            return Command::FAILURE;
        }

        if (!$input->getOption('yes') && !$io->confirm(
            sprintf(
                'Запустить перевод %s coins → %s (sender: %s)? Будут реальные покупки на рынке.',
                number_format($amount, 0, '.', ' '),
                $receiver->getEmail(),
                $senderLabel,
            ),
            false,
        )) {
            $io->note('Отменено.');

            return Command::SUCCESS;
        }

        try {
            $transfer = $this->orchestrator->createAndQueueTransfer($receiver->getId(), $amount, $senderId);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Transfer создан: %s', $transfer->getId()->toRfc4122()));

        if ($input->getOption('sync')) {
            $io->text('Синхронное выполнение…');
            ($this->executeTransferHandler)(new ExecuteTransferMessage(
                transferId: $transfer->getId()->toRfc4122(),
            ));
            $this->entityManager->clear();
            $transfer = $this->transferRepository->find($transfer->getId());
        } else {
            $io->note('Перевод поставлен в очередь. Убедитесь, что worker запущен: make worker');
        }

        if ($transfer instanceof Transfer) {
            $this->renderTransferStatus($io, $transfer);
        }

        return $transfer instanceof Transfer && $transfer->getStatus() === TransferStatus::Completed
            ? Command::SUCCESS
            : ($input->getOption('sync') ? Command::FAILURE : Command::SUCCESS);
    }

    private function renderTransferStatus(SymfonyStyle $io, Transfer $transfer): void
    {
        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();

        $io->definitionList(
            ['ID' => $transfer->getId()->toRfc4122()],
            ['Status' => $transfer->getStatus()->value],
            ['Sender' => $sender?->getEmail() ?? '—'],
            ['Receiver' => $receiver->getEmail()],
            ['Amount' => number_format($transfer->getTargetAmount(), 0, '.', ' ').' coins'],
            ['Error' => $transfer->getErrorMessage() ?? '—'],
        );

        if ($transfer->getStatus() === TransferStatus::Failed) {
            $io->error('Перевод завершился с ошибкой.');
        } elseif ($transfer->getStatus() === TransferStatus::Completed) {
            $io->success('Перевод выполнен.');
        }
    }
}
