<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Transfer\Service\SafetyService;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:account:list',
    description: 'Список EA-аккаунтов для переводов',
)]
final class AccountListCommand extends Command
{
    public function __construct(
        private AccountRepository $accountRepository,
        private SafetyService $safetyService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accounts = $this->accountRepository->findActive();
        $now = new \DateTimeImmutable();

        if ($accounts === []) {
            $io->warning('Аккаунтов нет. Добавьте: php bin/console app:account:add --email=...');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Email', 'Platform', 'Balance', 'Sales left', 'Cooldown until', 'Can send'],
            array_map(function ($account) use ($now): array {
                $cooldown = $account->getCooldownUntil();

                return [
                    $account->getId()->toRfc4122(),
                    $account->getEmail(),
                    $account->getPlatform()->value,
                    $account->getBalance() !== null ? number_format($account->getBalance(), 0, '.', ' ') : '—',
                    (string) $this->safetyService->getRemainingDailySales($account, $now),
                    $cooldown?->format('Y-m-d H:i') ?? '—',
                    $this->safetyService->canAccountSend($account, $now) ? 'yes' : 'no',
                ];
            }, $accounts),
        );

        return Command::SUCCESS;
    }
}
