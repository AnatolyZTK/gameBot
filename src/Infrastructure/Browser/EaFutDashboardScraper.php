<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use Symfony\Component\Panther\Client;
use Throwable;

final class EaFutDashboardScraper
{
    private const FUT_WEB_APP_URL = 'https://www.ea.com/ea-sports-fc/ultimate-team/web-app/';

    public function __construct(
        private readonly StealthChromeClientFactory $browserFactory,
        private readonly BrowserProfileSnapshotStorage $profileStorage,
    ) {
    }

    public function scrape(string $email): EaFutDashboardData
    {
        $client = null;

        try {
            $accountKey = $this->profileStorage->accountKeyFromEmail($email);
            $client = $this->browserFactory->createForAccount($accountKey);
            $this->browserFactory->prepare($client);
            $client->request('GET', self::FUT_WEB_APP_URL);
            usleep(2_000_000);
            $this->browserFactory->afterNavigation($client);
            $this->browserFactory->waitForInteractive($client);

            if (!$this->waitForDashboard($client)) {
                if ($this->browserFactory->isLoginScreenVisible($client)) {
                    return EaFutDashboardData::failure(
                        'Сессия EA истекла. Запустите: docker compose exec php php bin/console app:scrape:ea',
                    );
                }

                return EaFutDashboardData::failure('Не удалось загрузить данные FUT Web App в отведённое время.');
            }

            return $this->mapExtractedData($this->extractDashboard($client));
        } catch (Throwable $exception) {
            return EaFutDashboardData::failure($exception->getMessage());
        } finally {
            try {
                $client?->quit();
            } catch (Throwable) {
            }

            $this->browserFactory->stopProxyRelay();
            $this->profileStorage->repairPermissionsForWebServer();
        }
    }

    private function waitForDashboard(Client $client): bool
    {
        if (!$this->browserFactory->waitForStableSession($client, 3.0, 90.0)) {
            return false;
        }

        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            if ($this->browserFactory->isLoginScreenVisible($client)) {
                return false;
            }

            $extracted = $this->extractDashboard($client);
            if ($this->hasDashboardData($extracted)) {
                return true;
            }

            if ($this->browserFactory->isFutAppAuthenticated($client)) {
                return true;
            }

            usleep(500_000);
        }

        return $this->browserFactory->isFutAppAuthenticated($client)
            && !$this->browserFactory->isLoginScreenVisible($client);
    }

    /**
     * @param array<string, mixed> $extracted
     */
    private function hasDashboardData(array $extracted): bool
    {
        if (!($extracted['authenticated'] ?? false)) {
            return false;
        }

        return is_string($extracted['coins'] ?? null)
            || (is_array($extracted['tiles'] ?? null) && $extracted['tiles'] !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractDashboard(Client $client): array
    {
        $result = $client->executeScript(<<<'JS'
const coinsElement = document.querySelector('.view-navbar-currency-coins');
const coins = coinsElement
  ? coinsElement.innerText.replace(/\s+/g, ' ').trim()
  : null;

const tiles = Array.from(document.querySelectorAll('.tileContent')).map((tile) => {
  const lines = tile.innerText
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);

  const titleElement = tile.querySelector('[class*="title"], h1, h2, h3, h4');
  const title = titleElement
    ? titleElement.innerText.trim()
    : (lines[0] ?? null);

  return {
    title: title || null,
    text: tile.innerText.trim(),
    lines,
  };
});

return {
  coins: coins || null,
  tiles,
  authenticated: !!document.querySelector('.ut-root-view') && !(function() {
    const login = document.querySelector('.ut-login-content');
    if (!login) return false;
    const style = getComputedStyle(login);
    return style.display !== 'none' && style.visibility !== 'hidden' && login.offsetParent !== null;
  })(),
};
JS);

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $extracted
     */
    private function mapExtractedData(array $extracted): EaFutDashboardData
    {
        $tiles = [];
        foreach ($extracted['tiles'] ?? [] as $tile) {
            if (!is_array($tile)) {
                continue;
            }

            $lines = [];
            foreach ($tile['lines'] ?? [] as $line) {
                if (is_string($line) && $line !== '') {
                    $lines[] = $line;
                }
            }

            $tiles[] = [
                'title' => is_string($tile['title'] ?? null) ? $tile['title'] : null,
                'text' => is_string($tile['text'] ?? null) ? $tile['text'] : implode("\n", $lines),
                'lines' => $lines,
            ];
        }

        return new EaFutDashboardData(
            coins: is_string($extracted['coins'] ?? null) ? $extracted['coins'] : null,
            tiles: $tiles,
            authenticated: (bool) ($extracted['authenticated'] ?? false),
        );
    }
}
