<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser\Fut;

use App\Domain\Transfer\Enum\Platform;
use App\Application\Transfer\Service\MathService;
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
        $minPrice = max(150, (int) floor($maxPrice * 0.85));

        return $this->searchPlayer($session, $player, $minPrice, $maxPrice);
    }

    public function buyPlayer(FutWebAppSession $session, Player $player, Platform $platform, int $maxPrice): bool
    {
        $this->clearUnassignedItems($session);
        $this->openTransferMarket($session);
        $minPrice = max(150, (int) floor($maxPrice * 0.85));
        if (!$this->searchPlayer($session, $player, $minPrice, $maxPrice)) {
            return false;
        }

        return $this->buyFirstSearchResult($session, $maxPrice);
    }

    /**
     * Покупка первой карточки на рынке с Buy Now ≤ maxPrice (без фильтра по имени).
     *
     * @return array{bought: bool, playerName: ?string}
     */
    public function buyAnyUnderPrice(FutWebAppSession $session, int $maxPrice): array
    {
        $this->clearUnassignedItems($session);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->openTransferMarket($session);
            if (!$this->searchAnyUnderPrice($session, $maxPrice)) {
                return ['bought' => false, 'playerName' => null];
            }

            $bought = $this->buyFirstSearchResult($session, $maxPrice);
            if ($bought) {
                usleep(1_500_000);
                // Остаёмся на купленной карточке (List on Transfer Market) —
                // НЕ уходим в Unassigned, иначе sell возьмёт чужую карту (Fuseini и т.п.)
                $name = $this->readDetailPanelName($session);

                return ['bought' => true, 'playerName' => $name];
            }

            $this->clearUnassignedItems($session);
        }

        return ['bought' => false, 'playerName' => null];
    }

    /**
     * Отправляет карточки из Unassigned в Club — иначе EA блокирует Buy Now («5 or more Unassigned»).
     */
    public function clearUnassignedItems(FutWebAppSession $session, int $maxItems = 20): int
    {
        $client = $session->client();
        $factory = $session->factory();
        $opened = $this->openUnassignedAndSelectFirst($session);

        // Если уже на Unassigned (после Take Me There) — open может вернуть false, проверим DOM
        if (!$opened) {
            $alreadyThere = (bool) $client->executeScript(<<<'JS'
return /unassigned/i.test(document.body.innerText || '')
  && !!document.querySelector('.listFUTItem, .ut-unassigned-view, button');
JS);
            if (!$alreadyThere) {
                $this->logger->info('Unassigned tile not found — nothing to clear');

                return 0;
            }
        }

        $cleared = 0;
        $lastRemaining = -1;
        $stuck = 0;

        for ($i = 0; $i < $maxItems; ++$i) {
            $factory->dismissBlockingOverlays($client);
            usleep(300_000);

            $state = $client->executeScript(<<<'JS'
const buttons = Array.from(document.querySelectorAll('button, .btn-standard, .ut-image-button-control'))
  .filter((el) => el.offsetParent || el.getClientRects().length)
  .map((el) => ({
    text: (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 40),
    aria: (el.getAttribute('aria-label') || '').slice(0, 40),
    cls: (el.className || '').toString().slice(0, 60),
    disabled: !!el.disabled,
  }));
const items = document.querySelectorAll('.listFUTItem').length;
const dialog = (document.querySelector('.ea-dialog-view, .view-modal-container')?.innerText || '')
  .replace(/\s+/g, ' ').trim().slice(0, 120);
return { buttons: buttons.slice(0, 40), items, dialog };
JS);
            $this->logger->info('Unassigned clear pass', [
                'pass' => $i,
                'state' => $state,
            ]);

            // Сначала закрыть/пройти диалог New Items Full
            try {
                foreach ($client->getWebDriver()->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                    '.ea-dialog-view button, .view-modal-container button'
                )) as $button) {
                    try {
                        if (str_contains(strtolower((string) $button->getText()), 'take me there')) {
                            $button->click();
                            usleep(2_000_000);
                            break;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            } catch (\Throwable) {
            }

            // Открыть Unassigned tile если ещё на Home
            $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-unassigned, .ut-unassigned-tile-view')
  || Array.from(document.querySelectorAll('[class*=tile], .ut-tile')).find((el) =>
    /unassigned/i.test(el.innerText || '')
  );
tile?.click();
JS);
            usleep(1_500_000);

            // Выбрать первую карточку — иначе нет Send to Club
            try {
                $items = $client->getWebDriver()->findElements(
                    \Facebook\WebDriver\WebDriverBy::cssSelector('.listFUTItem')
                );
                if ($items !== []) {
                    $items[0]->click();
                    usleep(1_200_000);
                }
            } catch (\Throwable) {
                $client->executeScript('document.querySelector(".listFUTItem")?.click();');
                usleep(1_200_000);
            }

            $afterSelect = $client->executeScript(<<<'JS'
return {
  snippet: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 500),
  actions: Array.from(document.querySelectorAll('button, .btn-standard, [class*="action"]'))
    .filter((el) => el.offsetParent || el.getClientRects().length)
    .map((el) => ((el.innerText || el.getAttribute('aria-label') || el.className || '') + '')
      .replace(/\s+/g, ' ').trim().slice(0, 50))
    .filter(Boolean)
    .slice(0, 40),
};
JS);
            $this->logger->info('Unassigned after item select', ['state' => $afterSelect]);

            // Меню «...» иногда содержит Send All
            try {
                $ellipsis = $client->getWebDriver()->findElements(
                    \Facebook\WebDriver\WebDriverBy::cssSelector('button.ellipsis-btn, .ut-image-button-control.ellipsis-btn')
                );
                if ($ellipsis !== []) {
                    $ellipsis[0]->click();
                    usleep(800_000);
                }
            } catch (\Throwable) {
            }

            $afterMenu = $client->executeScript(<<<'JS'
return Array.from(document.querySelectorAll('button, li, .btn-standard, .menu-item, [role="menuitem"]'))
  .filter((el) => el.offsetParent || el.getClientRects().length)
  .map((el) => (el.innerText || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim())
  .filter(Boolean)
  .slice(0, 40);
JS);
            $this->logger->info('Unassigned after ellipsis menu', ['actions' => $afterMenu]);

            $sentAll = (bool) $client->executeScript(<<<'JS'
const match = (el) => {
  const t = ((el.innerText || '') + ' ' + (el.getAttribute('aria-label') || '')).replace(/\s+/g, ' ');
  return /send all to (my )?club|store all in club|send all to/i.test(t);
};
const btn = Array.from(document.querySelectorAll('button, .btn-standard, li, .ut-button-group button')).find((el) =>
  !el.disabled && match(el)
);
if (!btn) return false;
btn.click();
return true;
JS);
            if ($sentAll) {
                usleep(2_500_000);
                ++$cleared;
                $this->logger->info('Cleared unassigned via Send All to Club');
                $client->executeScript(<<<'JS'
const dialog = document.querySelector('.ea-dialog-view, .view-modal-container');
const ok = dialog && Array.from(dialog.querySelectorAll('button'))
  .find((el) => /^(ok|yes|confirm)$/i.test((el.innerText || '').trim()));
ok?.click();
JS);
                usleep(1_500_000);
                break;
            }

            $sent = false;
            // Предпочитаем Transfer List — гарантированно освобождает Unassigned-слот
            foreach (['send to transfer list', 'send to my club', 'send to club'] as $wanted) {
                try {
                    foreach ($client->getWebDriver()->findElements(
                        \Facebook\WebDriver\WebDriverBy::cssSelector('button')
                    ) as $button) {
                        try {
                            if (!$button->isDisplayed() || !$button->isEnabled()) {
                                continue;
                            }
                            $text = strtolower(trim(preg_replace('/\s+/u', ' ', (string) $button->getText()) ?: ''));
                            if ($text !== $wanted && !str_starts_with($text, $wanted)) {
                                continue;
                            }
                            $button->click();
                            $sent = true;
                            break 2;
                        } catch (\Throwable) {
                            continue;
                        }
                    }
                } catch (\Throwable) {
                }
            }

            if (!$sent) {
                $sent = (bool) $client->executeScript(<<<'JS'
const prefs = [/send to transfer list/i, /send to my club/i, /^send to club$/i];
for (const re of prefs) {
  const btn = Array.from(document.querySelectorAll('button'))
    .find((el) => !el.disabled && re.test((el.innerText || '').replace(/\s+/g, ' ').trim()));
  if (btn) { btn.click(); return true; }
}
return false;
JS);
            }

            if (!$sent) {
                $remainingNow = (int) $client->executeScript('return document.querySelectorAll(".listFUTItem").length;');
                if ($remainingNow <= 0) {
                    break;
                }
                $this->logger->info('No Send to Club/Transfer List button', ['pass' => $i]);
                break;
            }

            ++$cleared;
            usleep(1_000_000);

            $client->executeScript(<<<'JS'
const dialog = document.querySelector('.ea-dialog-view, .view-modal-container');
if (!dialog) return;
const text = (dialog.innerText || '').toLowerCase();
const ok = Array.from(dialog.querySelectorAll('button'))
  .find((el) => /^(ok|yes|confirm)$/i.test((el.innerText || '').trim()));
const cancel = Array.from(dialog.querySelectorAll('button'))
  .find((el) => /cancel/i.test(el.innerText || ''));
if (/full|cannot|unable/i.test(text)) {
  cancel?.click();
} else {
  ok?.click();
}
JS);
            usleep(800_000);
            $factory->waitForInteractive($client);

            // Стоп, если карточек больше нет
            $remaining = (int) $client->executeScript('return document.querySelectorAll(".listFUTItem").length;');
            if ($remaining <= 0) {
                break;
            }
            if ($remaining === $lastRemaining) {
                ++$stuck;
                if ($stuck >= 3) {
                    $this->logger->warning('Unassigned clear stuck — item count not decreasing', [
                        'remaining' => $remaining,
                    ]);
                    break;
                }
            } else {
                $stuck = 0;
                $lastRemaining = $remaining;
            }
        }

        if ($cleared > 0) {
            $this->logger->info('Cleared unassigned items to club', ['count' => $cleared]);
        }

        return $cleared;
    }

    private function readDetailPanelName(FutWebAppSession $session): ?string
    {
        $name = $session->client()->executeScript(<<<'JS'
const nameEl = document.querySelector('.itemInfo .name, .ut-item-view--main .name, .entityContainer .name, .tns-name, .DetailPanel .name')
  || document.querySelector('.listFUTItem.selected .name');
return nameEl ? nameEl.innerText.trim().split('\n')[0].trim() : null;
JS);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function readListedOrSelectedName(FutWebAppSession $session): ?string
    {
        return $this->readDetailPanelName($session)
            ?? $this->openUnassignedAndReadFirstName($session);
    }

    public function sellForMinPrice(FutWebAppSession $session, Player $player, int $minPrice): bool
    {
        // Сразу после Buy Now часто доступна кнопка List on Transfer Market
        if ($this->openListPanelFromCurrentItem($session)) {
            return $this->listItemAtPrice($session, $minPrice, $minPrice);
        }

        if ($this->openUnassignedAndSelectFirst($session)) {
            return $this->listItemAtPrice($session, $minPrice, $minPrice);
        }

        $this->openTransferList($session);

        if (!$this->selectFirstTransferListItem($session)) {
            $this->logger->warning('Transfer list / unassigned item not found for listing', [
                'player' => $player->getName(),
            ]);

            return false;
        }

        return $this->listItemAtPrice($session, $minPrice, $minPrice);
    }

    private function openListPanelFromCurrentItem(FutWebAppSession $session): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();
        $factory->dismissBlockingOverlays($client);

        foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('button')) as $button) {
            try {
                if (!$button->isDisplayed()) {
                    continue;
                }
                $text = strtolower(trim((string) $button->getText()));
                if (!str_contains($text, 'list on transfer') && $text !== 'list') {
                    continue;
                }
                $button->click();
                usleep(1_500_000);
                $factory->waitForInteractive($client);

                return true;
            } catch (\Throwable) {
                continue;
            }
        }

        return (bool) $client->executeScript(<<<'JS'
const listBtn = Array.from(document.querySelectorAll('button')).find((el) => /list on transfer market/i.test(el.innerText || ''))
  || document.querySelector('.accordian button');
listBtn?.click();
return !!listBtn;
JS);
    }

    public function snipePlayer(FutWebAppSession $session, Player $player, Platform $platform, int $maxPrice): bool
    {
        $this->clearUnassignedItems($session);
        $binPrice = MathService::roundToValidBin($maxPrice);
        $candidatePrices = array_values(array_unique([
            $binPrice,
            MathService::roundUpToValidBin($maxPrice),
        ]));

        $this->openTransferMarket($session);

        for ($attempt = 0; $attempt < self::SEARCH_ATTEMPTS; ++$attempt) {
            $base = $candidatePrices[$attempt % count($candidatePrices)];
            $searchPrice = $this->shiftPriceForCacheRefresh($base, intdiv($attempt, max(1, count($candidatePrices))));

            $this->logger->info('Snipe attempt', [
                'player' => $player->getName(),
                'attempt' => $attempt + 1,
                'searchPrice' => $searchPrice,
                'requestedPrice' => $maxPrice,
            ]);

            $this->ensureTransferSearchFiltersVisible($session);

            // Имя + точный BIN надёжнее «голого» BIN (на 50k+ слишком много лотов)
            $found = $this->searchPlayer($session, $player, $searchPrice, $searchPrice)
                || $this->searchExactBuyNow($session, $searchPrice);

            if (!$found) {
                usleep(400_000);
                continue;
            }

            if ($this->buyMatchingSearchResult($session, $player, $searchPrice)) {
                return true;
            }

            usleep(600_000);
        }

        return false;
    }

    /**
     * Покупает лот из выдачи, предпочитая карточку с именем игрока.
     */
    private function buyMatchingSearchResult(FutWebAppSession $session, Player $player, int $maxPrice): bool
    {
        $client = $session->client();
        $needle = mb_strtolower($this->resolveSearchName($player->getName()));

        $index = (int) $client->executeScript(<<<'JS'
const [needle] = arguments;
const items = Array.from(document.querySelectorAll('.listFUTItem.has-auction-data, .listFUTItem'));
const idx = items.findIndex((el) => (el.innerText || '').toLowerCase().includes(needle));
return idx >= 0 ? idx : 0;
JS, [$needle]);

        $selected = (bool) $client->executeScript(<<<'JS'
const [index] = arguments;
const items = Array.from(document.querySelectorAll('.listFUTItem.has-auction-data, .listFUTItem'));
const item = items[index];
if (!item) return false;
item.scrollIntoView({block: 'center'});
(item.querySelector('.rowContent, .entityContainer') || item).click();
return true;
JS, [$index]);

        if ($selected) {
            usleep(800_000);
            if ($this->waitAndClickBuyNow($session, 4.0)) {
                usleep(800_000);
                $afterBuy = $client->executeScript(<<<'JS'
return {
  dialog: !!document.querySelector('.ea-dialog-view, .view-modal-container'),
  dialogText: (document.querySelector('.ea-dialog-view, .view-modal-container')?.innerText || '')
    .replace(/\s+/g, ' ').trim().slice(0, 200),
};
JS);
                $dialogText = is_array($afterBuy) ? (string) ($afterBuy['dialogText'] ?? '') : '';
                if (preg_match('/unassigned items|new items full|cannot get this item/i', $dialogText)) {
                    $this->logger->warning('Unassigned pile full during snipe', ['dialogText' => $dialogText]);
                    $client->executeScript(<<<'JS'
const dialog = document.querySelector('.ea-dialog-view, .view-modal-container');
const go = dialog && Array.from(dialog.querySelectorAll('button'))
  .find((el) => /take me there/i.test(el.innerText || ''));
go?.click();
JS);
                    usleep(2_000_000);
                    $this->clearUnassignedItems($session);

                    return false;
                }
                $confirmed = $this->confirmBuyDialog($session);
                try {
                    $success = $this->waitForPurchaseSuccess($session, 8.0);
                } catch (\Throwable) {
                    $success = false;
                }
                $this->logger->info('Buy attempt finished', [
                    'maxPrice' => $maxPrice,
                    'itemIndex' => $index,
                    'buyClicked' => true,
                    'confirmed' => $confirmed,
                    'success' => $success,
                    'matchedName' => true,
                ]);
                if ($success) {
                    return true;
                }
            }
        }

        return $this->buyFirstSearchResult($session, $maxPrice);
    }

    private function ensureTransferSearchFiltersVisible(FutWebAppSession $session): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();

        for ($i = 0; $i < 5; ++$i) {
            $factory->dismissBlockingOverlays($client);
            $ready = (bool) $client->executeScript(<<<'JS'
const inputs = Array.from(document.querySelectorAll('input.ut-number-input-control')).filter((el) => el.offsetParent);
const searchBtn = Array.from(document.querySelectorAll('button')).some((el) =>
  el.offsetParent && /^search$/i.test((el.innerText || '').trim())
);
return inputs.length >= 2 && searchBtn;
JS);
            if ($ready) {
                return;
            }

            $clicked = false;
            try {
                foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                    'button.ut-navigation-button-control, .ut-navigation-button-control'
                )) as $button) {
                    try {
                        if ($button->isDisplayed()) {
                            $button->click();
                            $clicked = true;
                            break;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            } catch (\Throwable) {
            }

            if (!$clicked) {
                $client->executeScript(<<<'JS'
const back = document.querySelector('button.ut-navigation-button-control, .ut-navigation-button-control')
  || Array.from(document.querySelectorAll('button')).find((el) => /back/i.test(el.innerText || ''));
back?.click();
JS);
            }
            usleep(800_000);
            $factory->waitForInteractive($client);
        }
    }

    private function searchExactBuyNow(FutWebAppSession $session, int $buyNowPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);
        $buyNowPrice = MathService::roundToValidBin($buyNowPrice);

        $this->ensureTransferSearchFiltersVisible($session);

        $client->executeScript(<<<'JS'
const input = document.querySelector('input.ut-text-input-control[placeholder*="Player"]')
  || document.querySelector('.ut-player-search-control input');
if (input) {
  const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
  input.focus();
  descriptor.set.call(input, '');
  input.dispatchEvent(new Event('input', { bubbles: true }));
}
JS);

        try {
            $this->setBuyNowPriceRange($session, $buyNowPrice, $buyNowPrice);
            $this->clickSearchButton($session);
        } catch (\Throwable $exception) {
            $this->logger->warning('Exact BIN search failed', ['error' => $exception->getMessage()]);

            return false;
        }

        usleep(3_000_000);
        $factory->waitForInteractive($client);

        $items = (int) $client->executeScript('return document.querySelectorAll(".listFUTItem").length;');
        $this->logger->info('Exact BIN search finished', ['buyNowPrice' => $buyNowPrice, 'items' => $items]);

        return $items > 0;
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

        $this->openTransfersHub($session);

        try {
            $factory->clickCssSelector($client, '.ut-tile-transfer-market');
        } catch (\Throwable) {
            $opened = (bool) $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-market')
  || Array.from(document.querySelectorAll('[class*=tile], button, a, .tile'))
    .find((el) => /transfer market|search the transfer market/i.test((el.innerText || '').replace(/\s+/g, ' ')));
if (!tile) return false;
tile.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
return true;
JS);
            if (!$opened) {
                throw new \RuntimeException('Не найдена плитка Transfer Market');
            }
        }

        usleep(2_500_000);
        $factory->waitForInteractive($client);

        $ready = (bool) $client->executeScript(<<<'JS'
return !!document.querySelector('.ut-market-search-filters-view, .ut-pinned-list, button.btn-standard.call-to-action, button.btn-standard.primary')
  || Array.from(document.querySelectorAll('button')).some((el) => /search/i.test(el.innerText || ''));
JS);
        if (!$ready) {
            // Иногда нужен повторный клик по заголовку плитки
            $client->executeScript(<<<'JS'
document.querySelector('.ut-tile-transfer-market .tileHeader, .ut-tile-transfer-market')?.click();
JS);
            usleep(2_500_000);
            $factory->waitForInteractive($client);
        }

        $blocked = $client->executeScript(<<<'JS'
const text = document.body?.innerText || '';
return /blocked from using the Transfer Market|breaking our rules|transfer market.*(unavailable|disabled)/i.test(text)
  ? text.replace(/\s+/g, ' ').trim().slice(0, 240)
  : null;
JS);
        if (is_string($blocked) && $blocked !== '') {
            throw new \RuntimeException('Transfer Market заблокирован для аккаунта: '.$blocked);
        }
    }

    private function openTransferList(FutWebAppSession $session): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        $this->openTransfersHub($session);

        try {
            $factory->clickCssSelector($client, '.ut-tile-transfer-list');
        } catch (\Throwable) {
            $opened = (bool) $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-list')
  || Array.from(document.querySelectorAll('[class*=tile], button, a, .tile'))
    .find((el) => /transfer list/i.test((el.innerText || '').replace(/\s+/g, ' ')));
if (!tile) return false;
tile.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
return true;
JS);
            if (!$opened) {
                throw new \RuntimeException('Не найдена плитка Transfer List');
            }
        }

        usleep(2_000_000);
        $factory->waitForInteractive($client);
    }

    private function openTransfersHub(FutWebAppSession $session): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);
        $factory->waitForInteractive($client);

        // Закрыть модалки / player picks, мешающие навигации
        $client->executeScript(<<<'JS'
const closeBtn = Array.from(document.querySelectorAll('button, .btn-standard'))
  .find((el) => /close|cancel|later|dismiss|ok/i.test((el.innerText || '').trim()) && el.offsetParent);
closeBtn?.click();
document.querySelectorAll('.ut-click-shield').forEach((el) => {
  el.classList.remove('showing');
  el.style.pointerEvents = 'none';
  el.style.display = 'none';
});
JS);
        usleep(500_000);

        try {
            $factory->clickCssSelector($client, '.ut-tab-bar-item.icon-transfer');
        } catch (\Throwable) {
            $client->executeScript(<<<'JS'
const tab = Array.from(document.querySelectorAll('.ut-tab-bar-item'))
  .find((el) => /^transfers$/i.test((el.innerText || '').trim()) || el.classList.contains('icon-transfer'));
if (tab) {
  tab.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
}
JS);
        }

        usleep(2_500_000);
        $factory->waitForInteractive($client);
        $factory->dismissBlockingOverlays($client);

        $onTransfers = (bool) $client->executeScript(<<<'JS'
const tab = document.querySelector('.ut-tab-bar-item.icon-transfer');
return !!tab && (tab.classList.contains('selected') || tab.getAttribute('aria-selected') === 'true');
JS);

        if (!$onTransfers) {
            // Повторная попытка через JS MouseEvent + class toggle check
            $client->executeScript(<<<'JS'
const tab = document.querySelector('.ut-tab-bar-item.icon-transfer');
if (!tab) return;
tab.click();
JS);
            usleep(2_500_000);
            $factory->waitForInteractive($client);
        }

        $onTransfers = (bool) $client->executeScript(<<<'JS'
const tab = document.querySelector('.ut-tab-bar-item.icon-transfer');
const hasMarket = !!document.querySelector('.ut-tile-transfer-market')
  || Array.from(document.querySelectorAll('[class*=tile]')).some((el) => /transfer market/i.test(el.innerText || ''));
return (!!tab && tab.classList.contains('selected')) || hasMarket;
JS);

        if (!$onTransfers) {
            $snippet = $client->executeScript('return (document.body?.innerText || "").replace(/\\s+/g, " ").trim().slice(0, 400);');
            throw new \RuntimeException('Не удалось открыть раздел Transfers. UI: '.$snippet);
        }
    }

    private function openUnassignedAndSelectFirst(FutWebAppSession $session): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        // Unassigned часто на Home
        try {
            $factory->clickCssSelector($client, '.ut-tab-bar-item.icon-home');
            usleep(2_000_000);
        } catch (\Throwable) {
            $client->executeScript("document.querySelector('.ut-tab-bar-item.icon-home')?.click();");
            usleep(2_000_000);
        }

        $opened = false;
        try {
            $factory->clickCssSelector($client, '.ut-unassigned-tile-view, .ut-tile-transfer-unassigned');
            $opened = true;
        } catch (\Throwable) {
            $opened = (bool) $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-unassigned, .ut-unassigned-tile-view')
  || Array.from(document.querySelectorAll('[class*=tile], button, a, .tile'))
    .find((el) => /unassigned/i.test(el.innerText || ''));
tile?.click();
return !!tile;
JS);
        }

        if (!$opened) {
            $this->openTransfersHub($session);
            try {
                $factory->clickCssSelector($client, '.ut-unassigned-tile-view, .ut-tile-transfer-unassigned');
                $opened = true;
            } catch (\Throwable) {
                $opened = (bool) $client->executeScript(<<<'JS'
const tile = document.querySelector('.ut-tile-transfer-unassigned, .ut-unassigned-tile-view')
  || Array.from(document.querySelectorAll('[class*=tile]')).find((el) => /unassigned/i.test(el.innerText || ''));
tile?.click();
return !!tile;
JS);
            }
        }

        if (!$opened) {
            return false;
        }

        usleep(2_000_000);
        $factory->waitForInteractive($client);

        return $this->selectFirstTransferListItem($session);
    }

    private function searchAnyUnderPrice(FutWebAppSession $session, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        $this->setBuyNowPriceRange($session, 150, $maxPrice);

        $values = $client->executeScript(<<<'JS'
return Array.from(document.querySelectorAll('input.ut-number-input-control'))
  .filter((el) => el.offsetParent)
  .map((el) => el.value);
JS);

        $this->clickSearchButton($session);

        usleep(4_000_000);
        $factory->waitForInteractive($client);

        $state = $client->executeScript(<<<'JS'
return {
  items: document.querySelectorAll('.listFUTItem').length,
  values: Array.from(document.querySelectorAll('input.ut-number-input-control')).filter((el) => el.offsetParent).map((el) => el.value),
  softban: /unable to find|too many|try again|blocked|market is unavailable|no results found/i.test(document.body.innerText || ''),
  snippet: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 400),
};
JS);

        $hasResults = is_array($state) && ((int) ($state['items'] ?? 0)) > 0;
        $this->logger->info('Any-under-price search finished', [
            'maxPrice' => $maxPrice,
            'preValues' => $values,
            'state' => $state,
            'hasResults' => $hasResults,
        ]);

        if (is_array($state) && ($state['softban'] ?? false)) {
            throw new \RuntimeException('Transfer Market softban/unavailable: '.($state['snippet'] ?? ''));
        }

        return $hasResults;
    }

    private function setBuyNowPriceRange(FutWebAppSession $session, int $minPrice, int $maxPrice): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->waitForInteractive($client);

        // Дождаться полей цены
        $deadline = microtime(true) + 10.0;
        $inputsReady = false;
        while (microtime(true) < $deadline) {
            $count = (int) $client->executeScript('return document.querySelectorAll("input.ut-number-input-control").length;');
            if ($count >= 2) {
                $inputsReady = true;
                break;
            }
            usleep(250_000);
        }
        if (!$inputsReady) {
            throw new \RuntimeException('Не найдены поля Buy Now Min/Max на Transfer Market');
        }

        $ok = (bool) $client->executeScript(<<<'JS'
const [minPrice, maxPrice] = arguments;
const inputs = Array.from(document.querySelectorAll('input.ut-number-input-control')).filter((el) => el.offsetParent);
if (inputs.length < 2) return false;

const setNative = (input, value) => {
  input.focus();
  input.select?.();
  const proto = window.HTMLInputElement.prototype;
  const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
  if (descriptor && descriptor.set) {
    descriptor.set.call(input, String(value));
  } else {
    input.value = String(value);
  }
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
  input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Enter' }));
  input.blur();
};

const minInput = inputs[inputs.length - 2];
const maxInput = inputs[inputs.length - 1];
setNative(minInput, minPrice);
setNative(maxInput, maxPrice);
const norm = (v) => String(v).replace(/[^\d]/g, '');
return norm(minInput.value) === String(minPrice) && norm(maxInput.value) === String(maxPrice);
JS, [$minPrice, $maxPrice]);

        if (!$ok) {
            $driver = $client->getWebDriver();
            $inputs = $driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('input.ut-number-input-control'));
            if (count($inputs) < 2) {
                throw new \RuntimeException('Не найдены поля Buy Now Min/Max на Transfer Market');
            }
            $this->typeIntoPriceInput($driver, $inputs[count($inputs) - 2], (string) $minPrice);
            $this->typeIntoPriceInput($driver, $inputs[count($inputs) - 1], (string) $maxPrice);
        }

        usleep(400_000);
    }

    private function clickSearchButton(FutWebAppSession $session): void
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);
        $factory->waitForInteractive($client);

        $driver = $client->getWebDriver();
        $buttons = $driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('button.btn-standard.primary, button.btn-standard'));
        foreach ($buttons as $button) {
            try {
                if (!$button->isDisplayed()) {
                    continue;
                }
                $text = trim((string) $button->getText());
                if (strcasecmp($text, 'Search') !== 0) {
                    continue;
                }
                $driver->executeScript('arguments[0].scrollIntoView({block:"center"});', [$button]);
                try {
                    $button->click();
                } catch (\Throwable) {
                    $driver->executeScript(<<<'JS'
const el = arguments[0];
['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((type) => {
  el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, view: window }));
});
JS, [$button]);
                }

                return;
            } catch (\Throwable) {
                continue;
            }
        }

        throw new \RuntimeException('Кнопка Search на Transfer Market не найдена');
    }

    private function typeIntoPriceInput(\Facebook\WebDriver\Remote\RemoteWebDriver $driver, mixed $input, string $value): void
    {
        $driver->executeScript('arguments[0].scrollIntoView({block:"center"}); arguments[0].click();', [$input]);
        usleep(200_000);
        try {
            $input->clear();
        } catch (\Throwable) {
        }
        $input->sendKeys(\Facebook\WebDriver\WebDriverKeys::CONTROL.'a');
        $input->sendKeys(\Facebook\WebDriver\WebDriverKeys::BACKSPACE);
        $input->sendKeys($value);
        $driver->executeScript(<<<'JS'
const el = arguments[0];
el.dispatchEvent(new Event('input', { bubbles: true }));
el.dispatchEvent(new Event('change', { bubbles: true }));
el.blur();
JS, [$input]);
        usleep(300_000);
    }

    private function openUnassignedAndReadFirstName(FutWebAppSession $session): ?string
    {
        if (!$this->openUnassignedAndSelectFirst($session)) {
            return null;
        }

        $name = $session->client()->executeScript(<<<'JS'
const nameEl = document.querySelector('.itemInfo .name, .ut-item-view--main .name, .entityContainer .name, .tns-name')
  || document.querySelector('.listFUTItem.selected .name')
  || document.querySelector('.listFUTItem .name');
return nameEl ? nameEl.innerText.trim() : null;
JS);

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function readSelectedItemName(FutWebAppSession $session): ?string
    {
        $client = $session->client();
        try {
            $client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('.listFUTItem.has-auction-data, .listFUTItem'))->click();
        } catch (\Throwable) {
            $client->executeScript(<<<'JS'
(document.querySelector('.listFUTItem.has-auction-data') || document.querySelector('.listFUTItem'))?.click();
JS);
        }
        usleep(600_000);

        $name = $client->executeScript(<<<'JS'
const nameEl = document.querySelector('.itemInfo .name, .ut-item-view--main .name, .entityContainer .name, .tns-name')
  || document.querySelector('.listFUTItem.selected .name')
  || document.querySelector('.listFUTItem .name');
return nameEl ? nameEl.innerText.trim() : null;
JS);

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function searchPlayer(FutWebAppSession $session, Player $player, int $minPrice, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();
        $factory->dismissBlockingOverlays($client);
        $minPrice = MathService::roundToValidBin($minPrice);
        $maxPrice = MathService::roundUpToValidBin($maxPrice);
        if ($minPrice > $maxPrice) {
            $minPrice = $maxPrice;
        }

        $this->ensureTransferSearchFiltersVisible($session);

        $searchName = $this->resolveSearchName($player->getName());
        $nameTyped = $this->typePlayerName($session, $searchName);

        usleep(1_500_000);

        // Выбрать игрока из подсказки (предпочитаем точное совпадение)
        $picked = (bool) $client->executeScript(<<<'JS'
const [wanted] = arguments;
const normalize = (s) => (s || '').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '').trim();
const want = normalize(wanted);
const candidates = Array.from(document.querySelectorAll(
  '.playerResultsList button, .ut-player-search-control button, .ut-button-group button, li button, .listFUTItem'
)).filter((el) => el.offsetParent);
const exact = candidates.find((el) => normalize(el.innerText).includes(want));
const button = exact || candidates[0];
button?.click();
return !!button;
JS, [$searchName]);

        usleep(800_000);

        try {
            $this->setBuyNowPriceRange($session, $minPrice, $maxPrice);
            $this->clickSearchButton($session);
        } catch (\Throwable $exception) {
            $this->logger->warning('Named player search failed', [
                'player' => $player->getName(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        usleep(3_000_000);
        $factory->waitForInteractive($client);

        $hasResults = (bool) $client->executeScript(<<<'JS'
return document.querySelectorAll('.listFUTItem.has-auction-data, .listFUTItem').length > 0;
JS);

        $this->logger->info('Transfer market search finished', [
            'player' => $player->getName(),
            'searchName' => $searchName,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'hasResults' => $hasResults,
            'nameTyped' => $nameTyped,
            'picked' => $picked,
        ]);

        return $hasResults;
    }

    private function resolveSearchName(string $fullName): string
    {
        $fullName = trim($fullName);
        // EA search лучше работает по фамилии для длинных имён
        $parts = preg_split('/\s+/', $fullName) ?: [$fullName];
        $last = (string) end($parts);

        return $last !== '' ? $last : $fullName;
    }

    private function typePlayerName(FutWebAppSession $session, string $name): bool
    {
        $client = $session->client();
        $driver = $client->getWebDriver();

        foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('input.ut-text-input-control, input[type="text"]')) as $input) {
            try {
                if (!$input->isDisplayed()) {
                    continue;
                }
                $ph = strtolower((string) $input->getAttribute('placeholder'));
                if ($ph !== '' && !str_contains($ph, 'player') && !str_contains($ph, 'type')) {
                    continue;
                }
                if ($ph === '' && !str_contains((string) $input->getAttribute('class'), 'ut-text-input')) {
                    continue;
                }
                $driver->executeScript('arguments[0].scrollIntoView({block:"center"}); arguments[0].click();', [$input]);
                usleep(150_000);
                try {
                    $input->clear();
                } catch (\Throwable) {
                }
                $input->sendKeys(\Facebook\WebDriver\WebDriverKeys::CONTROL.'a');
                $input->sendKeys(\Facebook\WebDriver\WebDriverKeys::BACKSPACE);
                $input->sendKeys($name);

                return true;
            } catch (\Throwable) {
                continue;
            }
        }

        return (bool) $client->executeScript(<<<'JS'
const [name] = arguments;
const input = document.querySelector('input.ut-text-input-control[placeholder*="Player"]')
  || document.querySelector('input.ut-text-input-control[placeholder*="player"]')
  || document.querySelector('.ut-player-search-control input')
  || Array.from(document.querySelectorAll('input.ut-text-input-control')).find((el) => el.offsetParent);
if (!input) return false;
const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
input.focus();
descriptor.set.call(input, name);
input.dispatchEvent(new Event('input', { bubbles: true }));
input.dispatchEvent(new Event('change', { bubbles: true }));
return true;
JS, [$name]);
    }

    private function buyFirstSearchResult(FutWebAppSession $session, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();

        $itemCount = (int) $client->executeScript(
            'return document.querySelectorAll(".listFUTItem.has-auction-data, .listFUTItem").length;'
        );
        $maxAttempts = min(8, max(1, $itemCount));

        for ($index = 0; $index < $maxAttempts; ++$index) {
            $factory->dismissBlockingOverlays($client);
            $factory->waitForInteractive($client);

            $selected = (bool) $client->executeScript(<<<'JS'
const [index] = arguments;
const items = Array.from(document.querySelectorAll('.listFUTItem.has-auction-data, .listFUTItem'));
const item = items[index];
if (!item) return false;
item.scrollIntoView({block: 'center', inline: 'nearest'});
const row = item.querySelector('.rowContent, .entityContainer, .player') || item;
// EA слушает и click, и pointer events на row
for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
  row.dispatchEvent(new MouseEvent(type, {bubbles: true, cancelable: true, view: window, buttons: 1}));
}
row.click();
item.click();
return true;
JS, [$index]);

            if (!$selected) {
                // Fallback: WebDriver native click
                try {
                    $items = $driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                        '.listFUTItem.has-auction-data, .listFUTItem'
                    ));
                    if (!isset($items[$index])) {
                        break;
                    }
                    $driver->executeScript('arguments[0].scrollIntoView({block:"center"});', [$items[$index]]);
                    $items[$index]->click();
                    $selected = true;
                } catch (\Throwable $exception) {
                    $this->logger->info('Failed to select search item', [
                        'index' => $index,
                        'error' => $exception->getMessage(),
                    ]);
                    continue;
                }
            }

            if (!$selected) {
                continue;
            }

            // Нативный WebDriver-клик поверх JS — надёжнее открывает DetailPanel
            try {
                $items = $driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                    '.listFUTItem.has-auction-data, .listFUTItem'
                ));
                if (isset($items[$index])) {
                    $items[$index]->click();
                }
            } catch (\Throwable) {
            }

            $ready = $this->waitForBuyPanel($session, 5.0);
            $diag = $client->executeScript(<<<'JS'
return {
  selected: !!document.querySelector('.listFUTItem.selected, .listFUTItem.hover'),
  itemCount: document.querySelectorAll('.listFUTItem').length,
  inputs: Array.from(document.querySelectorAll('input.ut-number-input-control'))
    .filter((el) => el.offsetParent).map((el) => el.value),
  buttons: Array.from(document.querySelectorAll('button')).filter((el) => el.offsetParent !== null || el.getClientRects().length)
    .map((el) => ({
      text: (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 60),
      cls: (el.className || '').toString().slice(0, 80),
      disabled: !!el.disabled,
    }))
    .slice(0, 30),
};
JS);
            if (!$ready) {
                $this->logger->info('Buy panel not ready after item select', [
                    'index' => $index,
                    'diag' => $diag,
                ]);
                continue;
            }

            $buyClicked = $this->waitAndClickBuyNow($session, 4.0);
            if (!$buyClicked) {
                $this->logger->info('Buy Now click failed', [
                    'index' => $index,
                    'diag' => $diag,
                ]);
                continue;
            }

            usleep(800_000);
            $afterBuy = $client->executeScript(<<<'JS'
return {
  dialog: !!document.querySelector('.ea-dialog-view, .view-modal-container'),
  dialogText: (document.querySelector('.ea-dialog-view, .view-modal-container')?.innerText || '')
    .replace(/\s+/g, ' ').trim().slice(0, 200),
  buttons: Array.from(document.querySelectorAll('.ea-dialog-view button, .view-modal-container button'))
    .filter((el) => el.offsetParent)
    .map((el) => (el.innerText || '').replace(/\s+/g, ' ').trim())
    .filter(Boolean)
    .slice(0, 10),
};
JS);
            $this->logger->info('After Buy Now click', ['index' => $index, 'state' => $afterBuy]);

            $dialogText = is_array($afterBuy) ? (string) ($afterBuy['dialogText'] ?? '') : '';
            if (preg_match('/unassigned items|new items full|cannot get this item/i', $dialogText)) {
                $this->logger->warning('Unassigned pile full during buy', ['dialogText' => $dialogText]);
                $tookThere = false;
                try {
                    foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                        '.ea-dialog-view button, .view-modal-container button'
                    )) as $button) {
                        try {
                            $text = strtolower(trim((string) $button->getText()));
                            if (!str_contains($text, 'take me there')) {
                                continue;
                            }
                            $button->click();
                            $tookThere = true;
                            break;
                        } catch (\Throwable) {
                            continue;
                        }
                    }
                } catch (\Throwable) {
                }
                if (!$tookThere) {
                    $client->executeScript(<<<'JS'
const dialog = document.querySelector('.ea-dialog-view, .view-modal-container');
const go = dialog && Array.from(dialog.querySelectorAll('button'))
  .find((el) => /take me there/i.test(el.innerText || ''));
go?.click();
JS);
                }
                usleep(3_000_000);
                $factory->waitForInteractive($client);
                $cleared = $this->clearUnassignedItems($session, 30);
                $this->logger->info('Cleared after New Items Full dialog', ['cleared' => $cleared]);

                return false;
            }

            $confirmed = $this->confirmBuyDialog($session);
            $success = false;
            try {
                $success = $this->waitForPurchaseSuccess($session, 8.0);
            } catch (\Throwable $exception) {
                $this->logger->warning('waitForPurchaseSuccess failed', [
                    'error' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
                ]);
                // После Ok браузер иногда перерисовывает DOM — короткая пауза и простой check
                usleep(2_000_000);
                try {
                    $success = (bool) $client->executeScript(<<<'JS'
return Array.from(document.querySelectorAll('button')).some((el) =>
  /send to transfer list|send to club|list on transfer/i.test(el.innerText || '')
);
JS);
                } catch (\Throwable) {
                    $success = false;
                }
            }

            $this->logger->info('Buy attempt finished', [
                'maxPrice' => $maxPrice,
                'itemIndex' => $index,
                'buyClicked' => true,
                'confirmed' => $confirmed,
                'success' => $success,
            ]);

            if ($success) {
                return true;
            }

            // Повторный Ok, если диалог ещё открыт
            if ($confirmed || (is_array($afterBuy) && ($afterBuy['dialog'] ?? false))) {
                try {
                    $this->confirmBuyDialog($session);
                    if ($this->waitForPurchaseSuccess($session, 5.0)) {
                        return true;
                    }
                } catch (\Throwable $exception) {
                    $this->logger->warning('Buy confirm retry failed', [
                        'error' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
                    ]);
                }
            }
        }

        $this->logger->info('Buy attempt finished', [
            'maxPrice' => $maxPrice,
            'buyClicked' => false,
            'confirmed' => false,
            'success' => false,
            'triedItems' => $maxAttempts,
        ]);

        return false;
    }

    private function waitForBuyPanel(FutWebAppSession $session, float $timeoutSeconds): bool
    {
        $client = $session->client();
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $ready = (bool) $client->executeScript(<<<'JS'
const buyBtn = document.querySelector('button.btn-standard.buyButton, button.buyButton')
  || Array.from(document.querySelectorAll('button')).find((el) => {
    if (!el.offsetParent || el.disabled) return false;
    return /^buy now/i.test((el.innerText || '').replace(/\s+/g, ' ').trim());
  });
const bidInputs = Array.from(document.querySelectorAll('input.ut-number-input-control'))
  .filter((el) => el.offsetParent).length > 0;
return !!buyBtn || bidInputs;
JS);
            if ($ready) {
                return true;
            }
            usleep(200_000);
        }

        return false;
    }

    private function waitAndClickBuyNow(FutWebAppSession $session, float $timeoutSeconds): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $factory->dismissBlockingOverlays($client);

            foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                'button.btn-standard.buyButton, button.buyButton'
            )) as $button) {
                try {
                    if (!$button->isDisplayed() || !$button->isEnabled()) {
                        continue;
                    }
                    $driver->executeScript('arguments[0].scrollIntoView({block:"center"});', [$button]);
                    usleep(100_000);
                    try {
                        $button->click();
                    } catch (\Throwable) {
                        $driver->executeScript('arguments[0].click();', [$button]);
                    }

                    return true;
                } catch (\Throwable) {
                    continue;
                }
            }

            // Buy Now по классу или тексту — не путать с Bid (currency-coins)
            $jsClicked = (bool) $client->executeScript(<<<'JS'
const isBuyNow = (el) => {
  if (!el || el.disabled) return false;
  const cls = (el.className || '').toString();
  if (cls.includes('buyButton')) return true;
  const t = (el.innerText || '').replace(/\s+/g, ' ').trim();
  return /buy\s*now/i.test(t);
};
const buyBtn = Array.from(document.querySelectorAll('button, .btn-standard, [role="button"]'))
  .find((el) => (el.offsetParent !== null || el.getClientRects().length > 0) && isBuyNow(el));
if (!buyBtn) return false;
buyBtn.scrollIntoView({block: 'center'});
buyBtn.click();
return true;
JS);
            if ($jsClicked) {
                return true;
            }

            usleep(250_000);
        }

        return false;
    }

    private function confirmBuyDialog(FutWebAppSession $session): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            try {
                $factory->dismissBlockingOverlays($client);
            } catch (\Throwable) {
            }

            // Сначала нативный клик по Ok в диалоге (EA часто игнорирует element.click() из JS)
            try {
                foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector(
                    '.ea-dialog-view button, .view-modal-container button'
                )) as $button) {
                    try {
                        if (!$button->isDisplayed() || !$button->isEnabled()) {
                            continue;
                        }
                        $text = strtolower(trim(preg_replace('/\s+/u', ' ', (string) $button->getText()) ?: ''));
                        if ($text !== 'ok' && $text !== 'yes' && $text !== 'confirm') {
                            continue;
                        }
                        $button->click();
                        usleep(500_000);

                        return true;
                    } catch (\Throwable) {
                        continue;
                    }
                }
            } catch (\Throwable) {
            }

            try {
                $jsConfirmed = (bool) $client->executeScript(<<<'JS'
const dialog = document.querySelector('.ea-dialog-view, .view-modal-container');
if (!dialog) return false;
const buttons = Array.from(dialog.querySelectorAll('button')).filter((el) => {
  const style = window.getComputedStyle(el);
  return style.display !== 'none' && style.visibility !== 'hidden' && !el.disabled;
});
const ok = buttons.find((el) => {
  const t = (el.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase();
  return t === 'ok' || t === 'yes' || t === 'confirm';
});
if (!ok) return false;
ok.click();
return true;
JS);
            } catch (\Throwable) {
                $jsConfirmed = false;
            }

            if ($jsConfirmed) {
                usleep(500_000);

                return true;
            }

            try {
                $hasDialog = (bool) $client->executeScript(
                    'return !!document.querySelector(".ea-dialog-view, .view-modal-container");'
                );
            } catch (\Throwable) {
                return false;
            }
            if (!$hasDialog) {
                return false;
            }

            usleep(200_000);
        }

        return false;
    }

    private function waitForPurchaseSuccess(FutWebAppSession $session, float $timeoutSeconds): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            try {
                $factory->dismissBlockingOverlays($client);
            } catch (\Throwable) {
            }

            try {
                $state = $client->executeScript(<<<'JS'
const buttons = Array.from(document.querySelectorAll('button')).map((el) => (el.innerText || '').replace(/\s+/g, ' ').trim());
const boughtBtn = buttons.some((t) => /send to transfer list|send to club|list on transfer/i.test(t));
const stillBuy = buttons.some((t) => /buy\s*now/i.test(t));
const dialog = !!document.querySelector('.ea-dialog-view, .view-modal-container');
const body = (document.body && document.body.innerText) ? document.body.innerText : '';
const error = /unable to buy|bid status changed|highest bidder|item is no longer available|insufficient funds/i.test(body);
const won = /congratulations|purchased|assigned to club|unassigned/i.test(body);
return { error: !!error, boughtBtn: !!boughtBtn, stillBuy: !!stillBuy, dialog: !!dialog, won: !!won };
JS);
            } catch (\Throwable) {
                usleep(400_000);
                continue;
            }

            if (is_array($state) && ($state['error'] ?? false)) {
                return false;
            }
            if (is_array($state) && (($state['boughtBtn'] ?? false) || (($state['won'] ?? false) && !($state['stillBuy'] ?? false)))) {
                return true;
            }

            usleep(300_000);
        }

        try {
            return (bool) $client->executeScript(<<<'JS'
return Array.from(document.querySelectorAll('button')).some((el) =>
  /send to transfer list|send to club|list on transfer/i.test(el.innerText || '')
);
JS);
        } catch (\Throwable) {
            return false;
        }
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
        $startPrice = MathService::roundToValidBin($startPrice);
        $buyNowPrice = MathService::roundUpToValidBin($buyNowPrice);
        // Start ≤ Buy Now
        if ($startPrice > $buyNowPrice) {
            $startPrice = $buyNowPrice;
        }

        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();

        $this->openListPanelFromCurrentItem($session);
        usleep(1_000_000);
        $factory->waitForInteractive($client);

        $inputs = $driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('.panelActions input.ut-text-input-control, .panelActions.open input.ut-text-input-control, input.ut-number-input-control'));
        $visible = [];
        foreach ($inputs as $input) {
            try {
                if ($input->isDisplayed()) {
                    $visible[] = $input;
                }
            } catch (\Throwable) {
            }
        }

        if (count($visible) >= 2) {
            $this->typeIntoPriceInput($driver, $visible[0], (string) $startPrice);
            $this->typeIntoPriceInput($driver, $visible[1], (string) $buyNowPrice);
        } else {
            $client->executeScript(<<<'JS'
const [startPrice, buyNowPrice] = arguments;
const inputs = Array.from(document.querySelectorAll('.panelActions.open input, .panelActions input.ut-number-input-control, .panelActions input.ut-text-input-control')).filter((i) => i.offsetParent);
const setNative = (input, value) => {
  if (!input) return;
  const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
  input.focus();
  descriptor.set.call(input, String(value));
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
};
if (inputs.length >= 2) {
  setNative(inputs[0], startPrice);
  setNative(inputs[1], buyNowPrice);
} else if (inputs.length === 1) {
  setNative(inputs[0], buyNowPrice);
}
JS, [$startPrice, $buyNowPrice]);
        }

        usleep(500_000);

        $listed = false;
        foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('button.btn-standard.primary, button.btn-standard')) as $button) {
            try {
                if (!$button->isDisplayed()) {
                    continue;
                }
                $text = strtolower(trim((string) $button->getText()));
                if (!preg_match('/list( item| for transfer)?/i', $text)) {
                    continue;
                }
                $button->click();
                $listed = true;
                break;
            } catch (\Throwable) {
                continue;
            }
        }

        if (!$listed) {
            $listed = (bool) $client->executeScript(<<<'JS'
const listBtn = Array.from(document.querySelectorAll('button.btn-standard.primary, button.btn-standard'))
  .find((el) => /list item|list for transfer|^list$/i.test((el.innerText || '').trim()));
listBtn?.click();
return !!listBtn;
JS);
        }

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
        $step = MathService::binStep($basePrice);
        $offsets = [0, $step, 2 * $step, -$step, -2 * $step];
        $shifted = $basePrice + ($offsets[$attempt] ?? 0);

        return MathService::roundToValidBin(max(150, $shifted));
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
