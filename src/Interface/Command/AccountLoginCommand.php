<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Transfer\Service\AccountIdentifierResolver;
use App\Infrastructure\Browser\EaFutLoginService;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:account:login',
    description: 'Войти в EA FUT и сохранить browser-профиль аккаунта из БД',
)]
final class AccountLoginCommand extends Command
{
    public function __construct(
        private AccountIdentifierResolver $accountResolver,
        private AccountRepository $accountRepository,
        private EaFutLoginService $loginService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email аккаунта (или all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Войти во все активные аккаунты');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accounts = [];
        if ($input->getOption('all') || $input->getOption('email') === 'all') {
            $accounts = $this->accountRepository->findActive();
        } else {
            $email = $input->getOption('email');
            if (!is_string($email) || trim($email) === '') {
                $io->error('Укажите --email=... или --all');

                return Command::FAILURE;
            }
            $accounts = [$this->accountResolver->resolve($email)];
        }

        if ($accounts === []) {
            $io->error('Нет аккаунтов. Сначала: php bin/console app:account:seed');

            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($accounts as $account) {
            $io->section($account->getEmail());
            try {
                $this->loginService->ensureLoggedIn($account);
                $io->success('OK: '.$account->getEmail());
            } catch (\Throwable $exception) {
                ++$failed;
                $io->error($exception->getMessage());
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
