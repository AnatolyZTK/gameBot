<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Futbin\PriceParserService;
use App\Infrastructure\Parser\FutbinJsonPriceParser;
use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:prices',
    description: 'Синхронизация цен игроков с Futbin',
)]
final class SyncPricesCommand extends Command
{
    public function __construct(
        private readonly PriceParserService $priceParserService,
        private readonly FutbinJsonPriceParser $jsonParser,
        private readonly PlayerRepository $playerRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('favorites-only', 'f', InputOption::VALUE_NONE, 'Обновить только избранных игроков')
            ->addOption('player-id', 'p', InputOption::VALUE_REQUIRED, 'Обновить одного игрока по futbin ID')
            ->addOption('fixture', null, InputOption::VALUE_REQUIRED, 'Импорт из локального JSON (playerPrices)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fixture = $input->getOption('fixture');
        if (is_string($fixture) && $fixture !== '') {
            return $this->importFixture($io, $fixture);
        }

        $playerId = $input->getOption('player-id');
        if (is_string($playerId) && $playerId !== '') {
            try {
                $player = $this->priceParserService->syncPlayer((int) $playerId);
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());
                $io->note('Попробуйте: php bin/console app:futbin:auth');

                return Command::FAILURE;
            }

            if ($player === null) {
                $io->error('Не удалось получить цену с Futbin для игрока '.$playerId);

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Обновлён %s: PS=%s Xbox=%s PC=%s',
                $player->getName(),
                $this->formatPrice($player->getPricePs()),
                $this->formatPrice($player->getPriceXbox()),
                $this->formatPrice($player->getPricePc()),
            ));

            return Command::SUCCESS;
        }

        $players = $input->getOption('favorites-only')
            ? $this->playerRepository->findFavorites()
            : $this->playerRepository->findAll();

        if ($players === []) {
            $io->warning('Нет игроков для синхронизации. Добавьте futbin_id через --player-id или --fixture.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Синхронизация %d игрок(ов)...', count($players)));
        $result = $this->priceParserService->syncPlayers($players);

        $io->success(sprintf(
            'Готово: обновлено %d, ошибок %d',
            $result['updated'],
            $result['failed'],
        ));

        if ($result['failed'] > 0 && $result['updated'] === 0) {
            $io->note('Futbin блокирует IP? Выполните: php bin/console app:futbin:auth');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function importFixture(SymfonyStyle $io, string $fixturePath): int
    {
        if (!is_file($fixturePath)) {
            $io->error('Файл не найден: '.$fixturePath);

            return Command::FAILURE;
        }

        $json = file_get_contents($fixturePath);
        if (!is_string($json)) {
            $io->error('Не удалось прочитать файл');

            return Command::FAILURE;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $io->error('Некорректный JSON');

            return Command::FAILURE;
        }

        $updated = 0;
        foreach (array_keys($data) as $futbinIdRaw) {
            $futbinId = (int) $futbinIdRaw;
            $quote = $this->jsonParser->parse($json, $futbinId, 'https://www.futbin.com/26/player/'.$futbinId);
            if ($quote === null) {
                continue;
            }

            $player = $this->playerRepository->findByFutbinId($futbinId);
            if ($player === null) {
                $player = new Player($futbinId, $quote->name, $quote->sourceUrl);
                $this->entityManager->persist($player);
            }

            $player->updatePrices(
                $quote->rating,
                $quote->position,
                $quote->pricePs,
                $quote->priceXbox,
                $quote->pricePc,
                new \DateTimeImmutable(),
            );
            ++$updated;
        }

        $this->entityManager->flush();
        $io->success('Импортировано игроков: '.$updated);

        return Command::SUCCESS;
    }

    private function formatPrice(?int $price): string
    {
        return $price !== null ? number_format($price, 0, '.', ' ') : '—';
    }
}
