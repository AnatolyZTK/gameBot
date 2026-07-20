<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser\Fut;

use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Player;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Автоматизация трансферного рынка EA FUT Web App.
 */
final class FutTransferMarketBrowser
{
    private const SEARCH_ATTEMPTS = 5;

    public function __construct(
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
    ) {
    }

    public function searchTransferMarket(FutWebAppSession $session, Player $player, int $maxPrice): bool
    {
        $this->openTransferMarket($session);

        return $this->searchPlayer($session, $player, $maxPrice);
    }

    public function buyPlayer(FutWebAppSession $session, Player $player, Platform $platform, int $maxPrice): bool
    {
        $this->openTransferMarket($session);
        if (!$this->searchPlayer($session, $player, $maxPrice)) {
            return false;
        }

        return $this->buyFirstSearchResult($session, $maxPrice);
    }

    public function sellForMinPrice(FutWebAppSession $session, Player $player, int $minPrice): bool
    {
        $this->openTransferList($session);

        if (!$this->selectFirstTransferListItem($session)) {
            $this->logger->warning('Transfer list item not found for listing', ['player' => $player->getName()]);

            return false;
        }

        return $this->listItemAtPrice($session, $minPrice, $minPrice);
    }

    public function snipePlayer(FutWebAppSession $session, Player $player, Platform $platform, int $maxPrice): bool
    {
        $this->openTransferMarket($session);

        for ($attempt = 0; $attempt < self::SEARCH_ATTEMPTS; ++$attempt) {
            $searchPrice = $this->shiftPriceForCacheRefresh($maxPrice, $attempt);

            $this->logger->info('Snipe attempt', [
                'player' => $player->getName(),
                'attempt' => $attempt + 1,
                'searchPrice' => $searchPrice,
            ]);

            if (!$this->searchPlayer($session, $player, $searchPrice)) {
                usleep(400_000);

                continue;
            }

            if ($this->buyFirstSearchResult($session, $searchPrice)) {
                return true;
            }

            usleep(600_000);
        }

        return false;
    }

    public function sellForMarketPrice(FutWebAppSession $session, Player $player, Platform $platform): bool
    {
        $marketPrice = $this->resolveMarketPrice($player, $platform);
        if ($marketPrice === null || $marketPrice <= 0) {
            throw new \RuntimeException('Нет рыночной цены для '.$player->getName().' на '.$platform->value);
        }

        $this->openTransferList($session);

        if (!$this->selectFirstTransferListItem($session)) {
            return false;
        }

        return $this->listItemAtPrice($session, $marketPrice, $marketPrice);
    }

    public function getCoins(FutWebAppSession $session): ?int
    {
        $raw = $session->client()->executeScript(<<<'JS'
const el = document.querySelector('.view-navbar-currency-coins');
if (!el) return null;
return el.innerText.replace(/[^\d]/g, '');
JS);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    private function openTransferMarket(FutWebAppSession $session): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);
        $factory->waitForInteractive($client);

        $client->executeScript(<<<'JS'
const clickByClass = (selector) => {
  const el = document.querySelector(selector);
  if (el) { el.click(); return true; }
  return false;
};
if (!clickByClass('.ut-tab-bar-item.icon-transfer')) {
  const tab = Array.from(document.querySelectorAll('.ut-tab-bar-item'))
    .find((el) => /transfer/i.test(el.className) || /transfer/i.test(el.innerText));
  tab?.click();
}
JS);
        usleep(1_500_000);

        $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-market')
  || Array.from(document.querySelectorAll('.tile, .ut-tile-content, button, a'))
    .find((el) => /transfer market/i.test(el.innerText || ''));
tile?.click();
JS);
        usleep(2_000_000);
        $factory->waitForInteractive($client);
    }

    private function openTransferList(FutWebAppSession $session): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        $client->executeScript(<<<'JS'
const clickByClass = (selector) => {
  const el = document.querySelector(selector);
  if (el) { el.click(); return true; }
  return false;
};
if (!clickByClass('.ut-tab-bar-item.icon-transfer')) {
  const tab = Array.from(document.querySelectorAll('.ut-tab-bar-item'))
    .find((el) => /transfer/i.test(el.className) || /transfer/i.test(el.innerText));
  tab?.click();
}
JS);
        usleep(1_500_000);

        $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-list')
  || Array.from(document.querySelectorAll('.tile, .ut-tile-content, button, a'))
    .find((el) => /transfer list/i.test(el.innerText || ''));
tile?.click();
JS);
        usleep(2_000_000);
        $factory->waitForInteractive($client);
    }

    private function searchPlayer(FutWebAppSession $session, Player $player, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        $result = $client->executeScript(<<<'JS'
const [name, maxPrice] = arguments;
const setInputValue = (input, value) => {
  if (!input) return false;
  input.focus();
  input.value = '';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.value = String(value);
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
  return true;
};

const playerTrigger = document.querySelector('.ut-market-search-filters-view .inline-list-btn')
  || document.querySelector('.ut-market-search-filters-view button.flat')
  || document.querySelector('.search-limited .btn-player-search');
playerTrigger?.click();

return {openedPlayerSearch: !!playerTrigger, name, maxPrice};
JS, [$player->getName(), $maxPrice]);

        usleep(800_000);

        $client->executeScript(<<<'JS'
const [name] = arguments;
const input = document.querySelector('.ut-player-search-control input')
  || document.querySelector('input.ut-text-input-control[placeholder*="Player"]')
  || document.querySelector('.player-search-input input');
if (!input) return false;
input.focus();
input.value = name;
input.dispatchEvent(new Event('input', { bubbles: true }));
return true;
JS, [$player->getName()]);

        usleep(1_200_000);

        $client->executeScript(<<<'JS'
const button = document.querySelector('.playerResultsList button')
  || document.querySelector('.listFUTItem')
  || Array.from(document.querySelectorAll('button')).find((el) => el.offsetParent !== null);
button?.click();
return !!button;
JS);

        usleep(800_000);

        $client->executeScript(<<<'JS'
const [maxPrice] = arguments;
const inputs = Array.from(document.querySelectorAll('.ut-market-search-filters-view input.ut-text-input-control'));
const setPrice = (idx, value) => {
  const input = inputs[idx];
  if (!input) return false;
  input.focus();
  input.value = String(value);
  input.dispatchEvent(new Event('change', { bubbles: true }));
  return true;
};
if (inputs.length >= 2) {
  setPrice(inputs.length - 2, maxPrice);
  setPrice(inputs.length - 1, maxPrice);
} else if (inputs.length === 1) {
  setPrice(0, maxPrice);
}
const searchBtn = document.querySelector('.ut-market-search-filters-view .btn-standard.call-to-action')
  || Array.from(document.querySelectorAll('button.btn-standard')).find((el) => /search/i.test(el.innerText));
searchBtn?.click();
return {inputs: inputs.length, searched: !!searchBtn, maxPrice};
JS, [$maxPrice]);

        usleep(2_500_000);
        $factory->waitForInteractive($client);

        $hasResults = (bool) $client->executeScript(<<<'JS'
return !!document.querySelector('.listFUTItem.has-auction-data, .listFUTItem, .ut-pinned-list-container .entityContainer');
JS);

        $this->logger->info('Transfer market search finished', [
            'player' => $player->getName(),
            'maxPrice' => $maxPrice,
            'hasResults' => $hasResults,
            'result' => $result,
        ]);

        return $hasResults;
    }

    private function buyFirstSearchResult(FutWebAppSession $session, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        $clicked = (bool) $client->executeScript(<<<'JS'
const item = document.querySelector('.listFUTItem.has-auction-data')
  || document.querySelector('.listFUTItem');
item?.click();
return !!item;
JS);
        if (!$clicked) {
            return false;
        }

        usleep(1_000_000);

        $client->executeScript(<<<'JS'
const buyBtn = document.querySelector('.btn-standard.buyButton.currency-coins')
  || document.querySelector('.buyButton')
  || Array.from(document.querySelectorAll('button')).find((el) => /buy now/i.test(el.innerText));
buyBtn?.click();
JS);

        usleep(800_000);

        $confirmed = (bool) $client->executeScript(<<<'JS'
const ok = document.querySelector('.ea-dialog-view .btn-standard.primary')
  || Array.from(document.querySelectorAll('.ea-dialog-view button, .view-modal-container button'))
    .find((el) => /ok|yes|confirm|buy/i.test(el.innerText || ''));
ok?.click();
return !!ok;
JS);

        usleep(1_500_000);

        $this->logger->info('Buy attempt finished', ['maxPrice' => $maxPrice, 'confirmed' => $confirmed]);

        return $confirmed;
    }

    private function selectFirstTransferListItem(FutWebAppSession $session): bool
    {
        $client = $session->client();

        return (bool) $client->executeScript(<<<'JS'
const item = document.querySelector('.listFUTItem.has-auction-data')
  || document.querySelector('.ut-transfer-list-view .listFUTItem')
  || document.querySelector('.listFUTItem');
item?.click();
return !!item;
JS);
    }

    private function listItemAtPrice(FutWebAppSession $session, int $startPrice, int $buyNowPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();

        $client->executeScript(<<<'JS'
const listBtn = Array.from(document.querySelectorAll('button')).find((el) => /list on transfer market/i.test(el.innerText || ''))
  || document.querySelector('.accordian button');
listBtn?.click();
JS);

        usleep(1_500_000);
        $factory->waitForInteractive($client);

        $client->executeScript(<<<'JS'
const [startPrice, buyNowPrice] = arguments;
const inputs = Array.from(document.querySelectorAll('.panelActions.open input.ut-text-input-control, .panelActions input.ut-text-input-control'));
const setPrice = (input, value) => {
  if (!input) return;
  input.focus();
  input.value = String(value);
  input.dispatchEvent(new Event('change', { bubbles: true }));
};
if (inputs.length >= 2) {
  setPrice(inputs[0], startPrice);
  setPrice(inputs[1], buyNowPrice);
} else if (inputs.length === 1) {
  setPrice(inputs[0], buyNowPrice);
}
JS, [$startPrice, $buyNowPrice]);

        usleep(500_000);

        $listed = (bool) $client->executeScript(<<<'JS'
const listBtn = Array.from(document.querySelectorAll('button.btn-standard.primary, button.btn-standard.call-to-action'))
  .find((el) => /list item|list for transfer|list/i.test(el.innerText || ''));
listBtn?.click();
return !!listBtn;
JS);

        usleep(1_500_000);

        $client->executeScript(<<<'JS'
const ok = document.querySelector('.ea-dialog-view .btn-standard.primary')
  || Array.from(document.querySelectorAll('.ea-dialog-view button'))
    .find((el) => /ok|yes|confirm/i.test(el.innerText || ''));
ok?.click();
JS);

        usleep(1_000_000);

        $this->logger->info('List item attempt finished', [
            'startPrice' => $startPrice,
            'buyNowPrice' => $buyNowPrice,
            'listed' => $listed,
        ]);

        return $listed;
    }

    private function shiftPriceForCacheRefresh(int $basePrice, int $attempt): int
    {
        $steps = [0, 250, 500, -250, -500];

        return max(150, $basePrice + ($steps[$attempt] ?? 0));
    }

    private function resolveMarketPrice(Player $player, Platform $platform): ?int
    {
        return match ($platform) {
            Platform::Ps => $player->getPricePs(),
            Platform::Xbox => $player->getPriceXbox(),
            Platform::Pc => $player->getPricePc(),
        };
    }
}
