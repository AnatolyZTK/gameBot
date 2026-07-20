<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Browser\TotpCodeProvider;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ea:totp',
    description: 'Показать текущий TOTP-код для сверки с приложением-аутентификатором EA',
)]
final class ShowEaTotpCommand extends Command
{
    public function __construct(
        private readonly TotpCodeProvider $totpCodeProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $secret = $_ENV['EA_2FA_SECRET'] ?? $_SERVER['EA_2FA_SECRET'] ?? getenv('EA_2FA_SECRET');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('Задайте переменную окружения EA_2FA_SECRET');
        }

        $code = $this->totpCodeProvider->generate($secret);
        $io->writeln(sprintf('Код: %s', $code));
        $io->writeln(sprintf('Время сервера: %s', date('c')));
        $io->note('Сверьте этот код с приложением Google Authenticator / EA App. Если не совпадает — обновите EA_2FA_SECRET.');

        return Command::SUCCESS;
    }
}
