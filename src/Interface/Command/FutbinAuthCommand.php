<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Futbin\FutbinBrowserFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:futbin:auth',
    description: 'Открыть Futbin в браузере и сохранить cookies для app:sync:prices',
)]
final class FutbinAuthCommand extends Command
{
    public function __construct(
        private readonly FutbinBrowserFetcher $browserFetcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Futbin auth');
        $io->note([
            'Открывается Futbin через Chrome (stealth + прокси).',
            'Если Cloudflare не пропускает автоматически — запустите с xvfb и headed:',
            'PANTHER_NO_HEADLESS=1 xvfb-run -a php bin/console app:futbin:auth',
            'Cookies сохранятся в var/futbin/cookies.json',
        ]);

        try {
            $quote = $this->browserFetcher->fetchQuote(231747);
            if ($quote === null) {
                $io->error('Не удалось получить данные игрока. Cloudflare блокирует доступ.');

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Cookies сохранены. Тест: %s — PS=%s Xbox=%s PC=%s',
                $quote->name,
                $this->formatPrice($quote->pricePs),
                $this->formatPrice($quote->priceXbox),
                $this->formatPrice($quote->pricePc),
            ));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function formatPrice(?int $price): string
    {
        return $price !== null ? number_format($price, 0, '.', ' ') : '—';
    }
}
