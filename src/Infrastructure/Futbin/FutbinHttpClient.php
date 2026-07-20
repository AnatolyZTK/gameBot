<?php

declare(strict_types=1);

namespace App\Infrastructure\Futbin;

use App\Infrastructure\Http\HttpsProxyResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FutbinHttpClient
{
    private const DEFAULT_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Accept' => 'application/json, text/html, */*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
        'X-Requested-With' => 'XMLHttpRequest',
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
    ];

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private HttpsProxyResolver $proxyResolver,
        private FutbinCookieProvider $cookieProvider,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
        private string $baseUrl,
        private int $requestDelayMs,
    ) {
        $options = [];
        $proxy = $this->proxyResolver->resolve();
        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        $this->httpClient = $options === [] ? $httpClient : $httpClient->withOptions($options);
    }

    public function fetchPlayerPrices(int $futbinId, string $gameVersion): string
    {
        return $this->fetch(sprintf('/%s/playerPrices?player=%d', $gameVersion, $futbinId), $gameVersion);
    }

    public function fetchPlayerPage(int $futbinId, string $gameVersion): string
    {
        return $this->fetch(sprintf('/%s/player/%d', $gameVersion, $futbinId), $gameVersion, acceptHtml: true);
    }

    private function fetch(string $path, string $gameVersion, bool $acceptHtml = false): string
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $this->delay();

        $headers = self::DEFAULT_HEADERS;
        if ($acceptHtml) {
            $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
            $headers['Sec-Fetch-Dest'] = 'document';
            $headers['Sec-Fetch-Mode'] = 'navigate';
        }

        $cookieHeader = $this->cookieProvider->getCookieHeader();
        if ($cookieHeader !== null) {
            $headers['Cookie'] = $cookieHeader;
        }

        $headers['Referer'] = rtrim($this->baseUrl, '/').'/'.$gameVersion.'/players';

        $this->logger->info('Futbin request', ['url' => $url, 'hasCookies' => $cookieHeader !== null]);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $headers,
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($statusCode >= 400 || $this->isCloudflareChallenge($body)) {
            throw new \RuntimeException(sprintf(
                'Futbin недоступен (HTTP %d). Сохраните cookies: php bin/console app:futbin:auth',
                $statusCode,
            ));
        }

        return $body;
    }

    private function isCloudflareChallenge(string $body): bool
    {
        return str_contains($body, 'Just a moment')
            || str_contains($body, 'Один момент')
            || str_contains($body, 'cf-chl');
    }

    private function delay(): void
    {
        if ($this->requestDelayMs <= 0) {
            return;
        }

        usleep($this->requestDelayMs * 1000);
    }
}
