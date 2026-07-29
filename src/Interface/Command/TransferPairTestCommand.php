<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Transfer\Service\AccountIdentifierResolver;
use App\Application\Transfer\Service\CrossAccountListAndSnipeService;
use App\Application\Transfer\Service\PlayerRepositoryResolver;
use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transfer:pair-test',
    description: 'Тест: купить на sender → выставить → снайпить на receiver',
)]
final class TransferPairTestCommand extends Command
{
    public function __construct(
        private AccountIdentifierResolver $accountResolver,
        private PlayerRepository $playerRepository,
        private PlayerRepositoryResolver $playerResolver,
        private CrossAccountListAndSnipeService $listAndSnipeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sender', 's', InputOption::VALUE_REQUIRED, 'Email/UUID отправителя')
            ->addOption('receiver', 'r', InputOption::VALUE_REQUIRED, 'Email/UUID получателя')
            ->addOption('player-id', 'p', InputOption::VALUE_REQUIRED, 'futbin_id игрока')
            ->addOption('player-name', null, InputOption::VALUE_REQUIRED, 'Имя игрока')
            ->addOption('any', null, InputOption::VALUE_NONE, 'Купить любую карточку до --buy-max (рекомендуется для теста)')
            ->addOption('buy-max', null, InputOption::VALUE_REQUIRED, 'Макс. цена покупки на sender', '1000')
            ->addOption('list-price', null, InputOption::VALUE_REQUIRED, 'Buy Now цена листинга для снайпа', '850')
            ->addOption('skip-buy', null, InputOption::VALUE_NONE, 'Не покупать — листить уже имеющуюся карточку')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Без подтверждения');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $senderOpt = $input->getOption('sender');
        $receiverOpt = $input->getOption('receiver');
        if (!is_string($senderOpt) || !is_string($receiverOpt) || $senderOpt === '' || $receiverOpt === '') {
            $io->error('Укажите --sender и --receiver');

            return Command::FAILURE;
        }

        try {
            $sender = $this->accountResolver->resolve($senderOpt);
            $receiver = $this->accountResolver->resolve($receiverOpt);
            $buyAny = (bool) $input->getOption('any');
            $player = $buyAny ? null : $this->resolvePlayer($input);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $buyMax = (int) $input->getOption('buy-max');
        $listPrice = (int) $input->getOption('list-price');
        $buyFirst = !$input->getOption('skip-buy');

        $io->definitionList(
            ['Sender' => $sender->getEmail()],
            ['Receiver' => $receiver->getEmail()],
            ['Player' => $buyAny ? '(любая до buy-max)' : ($player?->getName() ?? '—')],
            ['Buy max' => number_format($buyMax, 0, '.', ' ')],
            ['List price' => number_format($listPrice, 0, '.', ' ')],
            ['Buy first' => $buyFirst ? 'yes' : 'no'],
            ['Buy any' => $buyAny ? 'yes' : 'no'],
        );

        if (!$input->getOption('yes') && !$io->confirm('Выполнить реальные покупку/листинг/снайп?', false)) {
            $io->note('Отменено.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->listAndSnipeService->listAndSnipe(
                $sender,
                $receiver,
                $player,
                $buyMax,
                $listPrice,
                $buyFirst,
                $buyAny,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Pair-test завершён: '.$result['playerName']);
        $io->table(
            ['Metric', 'Value'],
            [
                ['Player', $result['playerName']],
                ['Sender coins before', $this->fmt($result['senderCoinsBefore'])],
                ['Sender coins after', $this->fmt($result['senderCoinsAfter'])],
                ['Receiver coins before', $this->fmt($result['receiverCoinsBefore'])],
                ['Receiver coins after', $this->fmt($result['receiverCoinsAfter'])],
                ['Bought on sender', $result['bought'] ? 'yes' : 'no'],
                ['Listed', $result['listed'] ? 'yes' : 'no'],
                ['Sniped on receiver', $result['sniped'] ? 'yes' : 'no'],
                ['List price', $this->fmt($result['listPrice'])],
            ],
        );

        return Command::SUCCESS;
    }

    private function resolvePlayer(InputInterface $input): Player
    {
        $playerId = $input->getOption('player-id');
        if (is_string($playerId) && ctype_digit($playerId)) {
            $player = $this->playerRepository->findByFutbinId((int) $playerId);
            if ($player === null) {
                throw new \RuntimeException('Игрок futbin:'.$playerId.' не найден.');
            }

            return $player;
        }

        $name = $input->getOption('player-name');
        if (!is_string($name) || trim($name) === '') {
            throw new \RuntimeException('Укажите --player-id, --player-name или --any');
        }

        return $this->playerResolver->resolveOrCreateByName($name);
    }

    private function fmt(?int $value): string
    {
        return $value === null ? '—' : number_format($value, 0, '.', ' ');
    }
}
