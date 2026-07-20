<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Persistence\Entity\Transfer;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use App\Interface\Command\Helper\TransferPlanRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:transfer:show',
    description: 'Детали перевода по ID',
)]
final class TransferShowCommand extends Command
{
    public function __construct(
        private TransferRepository $transferRepository,
        private TransferPlanRenderer $planRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'UUID перевода');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        if (!is_string($id) || !Uuid::isValid($id)) {
            $io->error('Укажите корректный UUID перевода');

            return Command::FAILURE;
        }

        $transfer = $this->transferRepository->find(Uuid::fromString($id));
        if (!$transfer instanceof Transfer) {
            $io->error('Transfer not found: '.$id);

            return Command::FAILURE;
        }

        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();

        $io->title('Transfer '.$transfer->getId()->toRfc4122());
        $io->definitionList(
            ['Status' => $transfer->getStatus()->value],
            ['Sender' => $sender?->getEmail() ?? '—'],
            ['Receiver' => $receiver->getEmail()],
            ['Amount' => number_format($transfer->getTargetAmount(), 0, '.', ' ').' coins'],
            ['Created' => $transfer->getCreatedAt()->format('Y-m-d H:i:s')],
            ['Completed' => $transfer->getCompletedAt()?->format('Y-m-d H:i:s') ?? '—'],
            ['Error' => $transfer->getErrorMessage() ?? '—'],
        );

        $plan = $transfer->getPlan();
        if (is_array($plan)) {
            $this->planRenderer->render(
                $io,
                $plan,
                sprintf('%s (%s)', $receiver->getEmail(), $receiver->getPlatform()->label()),
            );
        }

        return Command::SUCCESS;
    }
}
