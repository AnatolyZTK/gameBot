<?php

declare(strict_types=1);

namespace App\Infrastructure\Futbin;

use App\Infrastructure\Browser\StealthChromeClientFactory;
use App\Infrastructure\Parser\FutbinJsonPriceParser;
use App\Infrastructure\Parser\FutbinPlayerPageParser;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Panther\Client;

final class FutbinBrowserFetcher
{
    public function __construct(
        private StealthChromeClientFactory $browserFactory,
        private FutbinJsonPriceParser $jsonParser,
        private FutbinPlayerPageParser $htmlParser,
        private FutbinCookieProvider $cookieProvider,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
        private string $baseUrl,
        private string $gameVersion,
        private string $userDataDir,
    ) {
    }

    public function fetchPlayerPrices(int $futbinId): ?string
    {
        $client = null;

        try {
            $client = $this->createClient();
            $this->browserFactory->prepare($client);

            $playerUrl = sprintf('%s/%s/player/%d', rtrim($this->baseUrl, '/'), $this->gameVersion, $futbinId);
            $client->request('GET', $playerUrl);

            if (!$this->waitUntilReady($client)) {
                throw new \RuntimeException('Futbin не прошёл проверку Cloudflare за отведённое время.');
            }

            $this->persistCookies($client);

            $apiPath = sprintf('/%s/playerPrices?player=%d', $this->gameVersion, $futbinId);
            $json = $client->executeAsyncScript(<<<'JS'
const [path] = arguments;
return fetch(path, {credentials: 'include', headers: {'X-Requested-With': 'XMLHttpRequest'}})
  .then((response) => response.text())
  .catch((error) => JSON.stringify({__error: String(error)}));
JS, [$apiPath]);

            if (!is_string($json) || str_contains($json, '__error')) {
                return null;
            }

            if ($this->looksLikeJson($json)) {
                return $json;
            }

            return $client->getPageSource();
        } finally {
            try {
                $client?->quit();
            } catch (\Throwable) {
            }

            $this->browserFactory->stopProxyRelay();
        }
    }

    public function fetchQuote(int $futbinId): ?\App\Domain\Transfer\ValueObject\PlayerPriceQuote
    {
        $payload = $this->fetchPlayerPrices($futbinId);
        if ($payload === null) {
            return null;
        }

        $sourceUrl = sprintf('%s/%s/player/%d', rtrim($this->baseUrl, '/'), $this->gameVersion, $futbinId);

        if ($this->looksLikeJson($payload)) {
            return $this->jsonParser->parse($payload, $futbinId, $sourceUrl);
        }

        return $this->htmlParser->parse($payload, $sourceUrl);
    }

    private function createClient(): Client
    {
        @mkdir($this->userDataDir, 0777, true);
        @chmod($this->userDataDir, 0777);

        return $this->browserFactory->createWithProfileDirectory($this->userDataDir);
    }

    private function waitUntilReady(Client $client, float $timeoutSeconds = 90.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $state = $client->executeScript(<<<'JS'
const title = document.title || '';
const body = document.body?.innerText || '';
const blocked = /just a moment|один момент|security verification|проверки безопасности/i.test(title + ' ' + body);
const ready = !!document.querySelector('.playercard-26-name, h1.page-header-top, .pcdisplay-rat, .price-table');
return {blocked, ready};
JS);

            if (is_array($state) && ($state['ready'] ?? false)) {
                return true;
            }

            if (is_array($state) && !($state['blocked'] ?? true)) {
                return true;
            }

            usleep(1_000_000);
        }

        return false;
    }

    private function persistCookies(Client $client): void
    {
        $driver = $client->getWebDriver();
        if (!$driver instanceof RemoteWebDriver) {
            return;
        }

        $devtools = new ChromeDevToolsDriver($driver);
        $result = $devtools->execute('Network.getAllCookies');
        $cookies = $result['cookies'] ?? [];
        if (!is_array($cookies) || $cookies === []) {
            return;
        }

        $normalized = [];
        foreach ($cookies as $cookie) {
            if (!is_array($cookie) || !isset($cookie['name'], $cookie['value'])) {
                continue;
            }

            if (!str_contains((string) ($cookie['domain'] ?? ''), 'futbin')) {
                continue;
            }

            $normalized[] = [
                'name' => (string) $cookie['name'],
                'value' => (string) $cookie['value'],
                'domain' => (string) ($cookie['domain'] ?? ''),
                'path' => (string) ($cookie['path'] ?? '/'),
            ];
        }

        if ($normalized !== []) {
            $this->cookieProvider->save($normalized);
            $this->logger->info('Futbin cookies saved', ['count' => count($normalized)]);
        }
    }

    private function looksLikeJson(string $payload): bool
    {
        $trimmed = ltrim($payload);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }
}
