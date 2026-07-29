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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:account:seed',
    description: 'Заполнить БД тремя EA-аккаунтами (credentials + proxy)',
)]
final class AccountSeedCommand extends Command
{
    /** @var list<array{email: string, password: string, totp: string, proxy: string, platform: string}> */
    private const ACCOUNTS = [
        [
            'email' => 'mix3924@outlook.com',
            'password' => 'Qwerty1q2214W',
            'totp' => 'TQREUZGJMB64Q6OC',
            'proxy' => 'http://UCAxNu:nR6aZq@194.28.211.119:9842',
            'platform' => 'ps',
        ],
        [
            'email' => 'nik2733@outlook.com',
            'password' => 'Qwerty1q2214W',
            'totp' => 'ZH46SSXVQHX3IU4T',
            'proxy' => 'http://UCAxNu:nR6aZq@194.28.208.178:9838',
            'platform' => 'ps',
        ],
        [
            'email' => 'ole9725@outlook.com',
            'password' => 'Qwerty1q2214W',
            'totp' => 'Q7SWSXIC3SQ6ZEE3',
            'proxy' => 'http://UCAxNu:nR6aZq@194.28.208.244:9802',
            'platform' => 'ps',
        ],
    ];

    public function __construct(
        private AccountRepository $accountRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::ACCOUNTS as $row) {
            $account = $this->accountRepository->findByEmail($row['email']);
            if (!$account instanceof Account) {
                $account = new Account($row['email'], Platform::from($row['platform']));
                $this->entityManager->persist($account);
                $io->writeln('Создан: '.$row['email']);
            } else {
                $io->writeln('Обновлён: '.$row['email']);
            }

            $account->setCredentials($row['password'], $row['totp'], $row['proxy']);
        }

        $this->entityManager->flush();
        $io->success(sprintf('Аккаунтов в сиде: %d', count(self::ACCOUNTS)));

        return Command::SUCCESS;
    }
}
