<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

use App\Infrastructure\Browser\Fut\FutTransferMarketBrowser;
use App\Infrastructure\Browser\Fut\FutWebAppSessionFactory;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Player;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SniperService
{
    public function __construct(
        private FutWebAppSessionFactory $sessionFactory,
        private FutTransferMarketBrowser $marketBrowser,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
    ) {
    }

    public function buyPlayer(Account $account, Player $player, int $maxPrice): bool
    {
        return $this->runForAccount($account, function ($session) use ($account, $player, $maxPrice): bool {
            $this->logger->info('SniperService::buyPlayer', [
                'account' => $account->getEmail(),
                'player' => $player->getName(),
                'maxPrice' => $maxPrice,
            ]);

            return $this->marketBrowser->buyPlayer($session, $player, $account->getPlatform(), $maxPrice);
        });
    }

    public function sellForMinPrice(Account $account, Player $player, int $minPrice): bool
    {
        return $this->runForAccount($account, function ($session) use ($account, $player, $minPrice): bool {
            $this->logger->info('SniperService::sellForMinPrice', [
                'account' => $account->getEmail(),
                'player' => $player->getName(),
                'minPrice' => $minPrice,
            ]);

            return $this->marketBrowser->sellForMinPrice($session, $player, $minPrice);
        });
    }

    public function snipePlayer(Account $account, Player $player, int $maxPrice): bool
    {
        return $this->runForAccount($account, function ($session) use ($account, $player, $maxPrice): bool {
            $this->logger->info('SniperService::snipePlayer', [
                'account' => $account->getEmail(),
                'player' => $player->getName(),
                'maxPrice' => $maxPrice,
            ]);

            return $this->marketBrowser->snipePlayer($session, $player, $account->getPlatform(), $maxPrice);
        });
    }

    public function sellForMarketPrice(Account $account, Player $player): bool
    {
        return $this->runForAccount($account, function ($session) use ($account, $player): bool {
            $this->logger->info('SniperService::sellForMarketPrice', [
                'account' => $account->getEmail(),
                'player' => $player->getName(),
            ]);

            return $this->marketBrowser->sellForMarketPrice($session, $player, $account->getPlatform());
        });
    }

    private function runForAccount(Account $account, callable $callback): bool
    {
        try {
            return (bool) $this->sessionFactory->withSession($account->getEmail(), $callback);
        } catch (\Throwable $exception) {
            $this->logger->error('Sniper operation failed', [
                'account' => $account->getEmail(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
