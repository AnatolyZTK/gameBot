<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Transfer\Service\AccountIdentifierResolver;
use App\Application\Transfer\Service\TransferOrchestrator;
use App\Interface\Command\Helper\TransferPlanRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transfer:plan',
    description: 'Рассчитать план перевода монет (без выполнения)',
)]
final class TransferPlanCommand extends Command
{
    public function __construct(
        private AccountIdentifierResolver $accountResolver,
        private TransferOrchestrator $orchestrator,
        private TransferPlanRenderer $planRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('receiver', 'r', InputOption::VALUE_REQUIRED, 'Email или UUID получателя')
            ->addOption('amount', 'a', InputOption::VALUE_REQUIRED, 'Сумма перевода (coins)');
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

        try {
            $receiver = $this->accountResolver->resolve($receiverIdentifier);
            $plan = $this->orchestrator->planTransfer($receiver->getId(), (int) $amountRaw);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->planRenderer->render(
            $io,
            $plan,
            sprintf('%s (%s)', $receiver->getEmail(), $receiver->getPlatform()->label()),
        );

        return Command::SUCCESS;
    }
}
