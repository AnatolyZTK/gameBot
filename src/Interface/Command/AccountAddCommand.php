<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:account:add',
    description: 'Добавить EA-аккаунт для переводов',
)]
final class AccountAddCommand extends Command
{
    public function __construct(
        private AccountRepository $accountRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email EA-аккаунта')
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'Платформа: ps, xbox, pc', 'ps');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email');
        if (!is_string($email) || trim($email) === '') {
            $email = $_ENV['EA_LOGIN_EMAIL'] ?? getenv('EA_LOGIN_EMAIL') ?: null;
        }
        if (!is_string($email) || trim($email) === '') {
            $io->error('Укажите --email или EA_LOGIN_EMAIL в .env');

            return Command::FAILURE;
        }

        $platformValue = $input->getOption('platform');
        if (!is_string($platformValue) || $platformValue === '') {
            $io->error('Укажите --platform (ps, xbox, pc)');

            return Command::FAILURE;
        }

        try {
            $platform = Platform::from(strtolower($platformValue));
        } catch (\ValueError) {
            $io->error('Неверная платформа. Допустимо: ps, xbox, pc');

            return Command::FAILURE;
        }

        $existing = $this->accountRepository->findByEmail($email);
        if ($existing instanceof Account) {
            $io->warning(sprintf(
                'Аккаунт уже существует: %s (%s, id=%s)',
                $existing->getEmail(),
                $existing->getPlatform()->label(),
                $existing->getId()->toRfc4122(),
            ));

            return Command::SUCCESS;
        }

        $account = new Account($email, $platform);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Аккаунт добавлен: %s (%s), id=%s',
            $account->getEmail(),
            $account->getPlatform()->label(),
            $account->getId()->toRfc4122(),
        ));

        return Command::SUCCESS;
    }
}
