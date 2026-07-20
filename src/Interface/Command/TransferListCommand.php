<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Persistence\Repository\TransferRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transfer:list',
    description: 'Список последних переводов',
)]
final class TransferListCommand extends Command
{
    public function __construct(
        private TransferRepository $transferRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Количество записей', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limitRaw = $input->getOption('limit');
        $limit = is_string($limitRaw) && ctype_digit($limitRaw) ? (int) $limitRaw : 20;

        $transfers = $this->transferRepository->findRecent($limit);
        if ($transfers === []) {
            $io->warning('Переводов пока нет.');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Status', 'Sender', 'Receiver', 'Amount', 'Created'],
            array_map(static function ($transfer): array {
                return [
                    $transfer->getId()->toRfc4122(),
                    $transfer->getStatus()->value,
                    $transfer->getSenderAccount()?->getEmail() ?? '—',
                    $transfer->getReceiverAccount()->getEmail(),
                    number_format($transfer->getTargetAmount(), 0, '.', ' '),
                    $transfer->getCreatedAt()->format('Y-m-d H:i'),
                ];
            }, $transfers),
        );

        return Command::SUCCESS;
    }
}
