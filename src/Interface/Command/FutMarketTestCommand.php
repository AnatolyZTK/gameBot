<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Browser\Fut\FutTransferMarketBrowser;
use App\Infrastructure\Browser\Fut\FutWebAppSessionFactory;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fut:market-test',
    description: 'Проверка доступа к трансферному рынку FUT (coins + search)',
)]
final class FutMarketTestCommand extends Command
{
    public function __construct(
        private readonly FutWebAppSessionFactory $sessionFactory,
        private readonly FutTransferMarketBrowser $marketBrowser,
        private readonly PlayerRepository $playerRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email аккаунта EA')
            ->addOption('player-id', 'p', InputOption::VALUE_REQUIRED, 'futbin_id игрока для тестового поиска')
            ->addOption('max-price', null, InputOption::VALUE_REQUIRED, 'Макс. цена поиска', '15000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getOption('email');
        if (!is_string($email) || $email === '') {
            $email = $_ENV['EA_LOGIN_EMAIL'] ?? getenv('EA_LOGIN_EMAIL') ?: null;
        }
        if (!is_string($email) || $email === '') {
            $io->error('Укажите --email или EA_LOGIN_EMAIL в .env');

            return Command::FAILURE;
        }

        try {
            $result = $this->sessionFactory->withSession($email, function ($session) use ($input, $io): array {
                $coins = $this->marketBrowser->getCoins($session);
                $io->writeln('Coins: '.($coins !== null ? number_format($coins, 0, '.', ' ') : '—'));

                $playerId = $input->getOption('player-id');
                if (!is_string($playerId) || $playerId === '') {
                    return ['coins' => $coins, 'search' => null];
                }

                $player = $this->playerRepository->findByFutbinId((int) $playerId);
                if ($player === null) {
                    throw new \RuntimeException('Игрок futbin:'.$playerId.' не найден в БД');
                }

                $maxPrice = (int) $input->getOption('max-price');
                $found = $this->marketBrowser->searchTransferMarket($session, $player, $maxPrice);

                return ['coins' => $coins, 'search' => $found];
            });

            if ($result['search'] === null) {
                $io->success('FUT-сессия открыта, монеты: '.($result['coins'] ?? '—'));

                return Command::SUCCESS;
            }

            $io->success($result['search'] ? 'Поиск/покупка: OK' : 'Поиск выполнен, карточка не куплена');

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
