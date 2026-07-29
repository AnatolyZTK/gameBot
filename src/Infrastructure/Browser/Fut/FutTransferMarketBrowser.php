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

    /**
     * Покупка первой карточки на рынке с Buy Now ≤ maxPrice (без фильтра по имени).
     *
     * @return array{bought: bool, playerName: ?string}
     */
    public function buyAnyUnderPrice(FutWebAppSession $session, int $maxPrice): array
    {
        $this->openTransferMarket($session);
        if (!$this->searchAnyUnderPrice($session, $maxPrice)) {
            return ['bought' => false, 'playerName' => null];
        }

        $nameBefore = $this->readSelectedItemName($session);
        $bought = $this->buyFirstSearchResult($session, $maxPrice);
        if (!$bought) {
            return ['bought' => false, 'playerName' => $nameBefore];
        }

        usleep(2_000_000);
        // Имя с выбранной карточки до покупки надёжнее, чем первый Unassigned
        $name = $nameBefore ?? $this->openUnassignedAndReadFirstName($session);

        return ['bought' => true, 'playerName' => $name];
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
        $this->openTransferMarket($session);

        for ($attempt = 0; $attempt < self::SEARCH_ATTEMPTS; ++$attempt) {
            $searchPrice = $this->shiftPriceForCacheRefresh($maxPrice, $attempt);

            $this->logger->info('Snipe attempt', [
                'player' => $player->getName(),
                'attempt' => $attempt + 1,
                'searchPrice' => $searchPrice,
            ]);

            // Сначала точный BIN без имени (классический снайп), затем с именем
            $found = $this->searchExactBuyNow($session, $searchPrice)
                || $this->searchPlayer($session, $player, $searchPrice);

            if (!$found) {
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

    private function searchExactBuyNow(FutWebAppSession $session, int $buyNowPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);

        // Закрыть дропдаун игрока / вернуться к фильтрам
        $client->executeScript(<<<'JS'
document.activeElement?.blur?.();
const back = Array.from(document.querySelectorAll('button, .ut-navigation-button-control'))
  .find((el) => /back/i.test((el.innerText || el.getAttribute('aria-label') || '')));
back?.click();
JS);
        usleep(500_000);
        $client->executeScript('document.dispatchEvent(new KeyboardEvent("keydown", {key:"Escape", bubbles:true}));');
        usleep(300_000);

        // Clear player name
        $client->executeScript(<<<'JS'
const input = document.querySelector('input.ut-text-input-control[placeholder*="Player"]');
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

    private function searchPlayer(FutWebAppSession $session, Player $player, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $driver = $client->getWebDriver();
        $factory->dismissBlockingOverlays($client);

        // Если уже на результатах — вернуться к фильтрам
        $client->executeScript(<<<'JS'
const back = Array.from(document.querySelectorAll('button, .ut-navigation-button-control'))
  .find((el) => /back|search the transfer market/i.test((el.innerText || el.getAttribute('aria-label') || '')));
back?.click();
JS);
        usleep(800_000);

        $nameTyped = false;
        foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('input.ut-text-input-control')) as $input) {
            try {
                if (!$input->isDisplayed()) {
                    continue;
                }
                $ph = (string) $input->getAttribute('placeholder');
                if (!str_contains(strtolower($ph), 'player')) {
                    continue;
                }
                $input->click();
                $input->clear();
                $input->sendKeys($player->getName());
                $nameTyped = true;
                break;
            } catch (\Throwable) {
                continue;
            }
        }

        if (!$nameTyped) {
            $client->executeScript(<<<'JS'
const [name] = arguments;
const input = document.querySelector('input.ut-text-input-control[placeholder*="Player"]')
  || document.querySelector('.ut-player-search-control input');
if (!input) return false;
const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
input.focus();
descriptor.set.call(input, name);
input.dispatchEvent(new Event('input', { bubbles: true }));
return true;
JS, [$player->getName()]);
        }

        usleep(1_500_000);

        // Выбрать первого игрока из подсказки
        $client->executeScript(<<<'JS'
const button = document.querySelector('.playerResultsList button, .ut-player-search-control .btn-text')
  || document.querySelector('.listFUTItem')
  || Array.from(document.querySelectorAll('button, li')).find((el) => el.offsetParent && /playerResults|result/i.test(el.className));
button?.click();
JS);
        usleep(800_000);

        // Exact BIN: min=max=maxPrice для точного снайпа
        $this->setBuyNowPriceRange($session, $maxPrice, $maxPrice);
        $this->clickSearchButton($session);

        usleep(3_000_000);
        $factory->waitForInteractive($client);

        $hasResults = (bool) $client->executeScript(<<<'JS'
return document.querySelectorAll('.listFUTItem.has-auction-data, .listFUTItem').length > 0;
JS);

        $this->logger->info('Transfer market search finished', [
            'player' => $player->getName(),
            'maxPrice' => $maxPrice,
            'hasResults' => $hasResults,
            'nameTyped' => $nameTyped,
        ]);

        return $hasResults;
    }

    private function buyFirstSearchResult(FutWebAppSession $session, int $maxPrice): bool
    {
        $client = $session->client();
        $factory = $session->factory();
        $factory->dismissBlockingOverlays($client);
        $driver = $client->getWebDriver();

        try {
            $item = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('.listFUTItem.has-auction-data, .listFUTItem'));
            $driver->executeScript('arguments[0].scrollIntoView({block:"center"});', [$item]);
            $item->click();
        } catch (\Throwable) {
            $clicked = (bool) $client->executeScript(<<<'JS'
const item = document.querySelector('.listFUTItem.has-auction-data') || document.querySelector('.listFUTItem');
item?.click();
return !!item;
JS);
            if (!$clicked) {
                return false;
            }
        }

        usleep(1_200_000);
        $factory->waitForInteractive($client);

        $buyClicked = false;
        foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('button.btn-standard.buyButton, button.buyButton, button.btn-standard')) as $button) {
            try {
                if (!$button->isDisplayed()) {
                    continue;
                }
                $text = strtolower(trim((string) $button->getText()));
                $cls = (string) $button->getAttribute('class');
                if (!str_contains($cls, 'buyButton') && !str_contains($text, 'buy now') && $text !== 'buy') {
                    continue;
                }
                $button->click();
                $buyClicked = true;
                break;
            } catch (\Throwable) {
                continue;
            }
        }

        if (!$buyClicked) {
            $buyClicked = (bool) $client->executeScript(<<<'JS'
const buyBtn = document.querySelector('.btn-standard.buyButton.currency-coins, .buyButton')
  || Array.from(document.querySelectorAll('button')).find((el) => /buy now/i.test(el.innerText || ''));
buyBtn?.click();
return !!buyBtn;
JS);
        }

        usleep(1_000_000);

        $confirmed = false;
        foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('.ea-dialog-view button, .view-modal-container button, button.btn-standard')) as $button) {
            try {
                if (!$button->isDisplayed()) {
                    continue;
                }
                $text = strtolower(trim((string) $button->getText()));
                if (!preg_match('/^(ok|yes|confirm|buy now|buy)$/i', $text) && !str_contains($text, 'ok')) {
                    continue;
                }
                $button->click();
                $confirmed = true;
                break;
            } catch (\Throwable) {
                continue;
            }
        }

        if (!$confirmed) {
            $confirmed = (bool) $client->executeScript(<<<'JS'
const ok = document.querySelector('.ea-dialog-view .btn-standard.primary')
  || Array.from(document.querySelectorAll('.ea-dialog-view button, .view-modal-container button, button'))
    .find((el) => /^(ok|yes|confirm|buy now)$/i.test((el.innerText || '').trim()));
ok?.click();
return !!ok;
JS);
        }

        usleep(2_000_000);
        $factory->waitForInteractive($client);

        // Успех: карточка исчезла из результатов / появился unassigned / нет диалога ошибки
        $success = (bool) $client->executeScript(<<<'JS'
const error = /unable to buy|bid status changed|highest bidder|item is no longer available/i.test(document.body.innerText || '');
if (error) return false;
const unassigned = /unassigned/i.test(document.body.innerText || '');
const boughtBtn = Array.from(document.querySelectorAll('button')).some((el) => /send to transfer list|send to club|list on transfer/i.test(el.innerText || ''));
return unassigned || boughtBtn || !document.querySelector('.ea-dialog-view');
JS);

        $this->logger->info('Buy attempt finished', [
            'maxPrice' => $maxPrice,
            'buyClicked' => $buyClicked,
            'confirmed' => $confirmed,
            'success' => $success,
        ]);

        return $buyClicked && ($confirmed || $success);
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
