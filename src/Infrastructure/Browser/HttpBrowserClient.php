<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use App\Domain\Scraping\Contract\BrowserClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP-клиент, имитирующий поведение браузера: User-Agent, cookies, задержки, retry.
 */
final class HttpBrowserClient implements BrowserClientInterface
{
    private const DEFAULT_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Connection' => 'keep-alive',
        'Upgrade-Insecure-Requests' => '1',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-User' => '?1',
    ];

    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
        private string $baseUrl,
        private int $requestDelayMs,
        private int $maxRetries,
    ) {
    }

    public function fetch(string $path): string
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $payload = []): string
    {
        return $this->request('POST', $path, [
            'json' => $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
            ],
        ]);
    }

  /**
   * @param array<string, mixed> $options
   */
    private function request(string $method, string $path, array $options = []): string
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $attempt = 0;

        do {
            ++$attempt;
            $this->humanDelay();

            $options['headers'] = array_merge(
                self::DEFAULT_HEADERS,
                $this->cookieHeader(),
                $options['headers'] ?? [],
            );

            try {
                $response = $this->httpClient->request($method, $url, $options);
                $this->storeCookies($response->getHeaders(false));
                $status = $response->getStatusCode();
                $body = $response->getContent();

                if ($status >= 500) {
                    throw new \RuntimeException(sprintf('Server error %d for %s', $status, $url));
                }

                if ($status === 429) {
                    $this->logger->warning('Rate limited, backing off', ['url' => $url, 'attempt' => $attempt]);
                    usleep(random_int(3_000_000, 8_000_000));
                    continue;
                }

                return $body;
            } catch (\Throwable $e) {
                $this->logger->error('Request failed', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }

                usleep(random_int(1_000_000, 3_000_000) * $attempt);
            }
        } while ($attempt < $this->maxRetries);

        throw new \RuntimeException(sprintf('Failed to fetch %s after %d attempts', $url, $this->maxRetries));
    }

    private function humanDelay(): void
    {
        $jitter = random_int(-200, 400);
        usleep(max(0, ($this->requestDelayMs + $jitter)) * 1000);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function storeCookies(array $headers): void
    {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $pair = explode(';', $cookie, 2)[0];
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($name !== '') {
                $this->cookies[$name] = $value;
            }
        }
    }

    /** @return array<string, string> */
    private function cookieHeader(): array
    {
        if ($this->cookies === []) {
            return [];
        }

        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return ['Cookie' => implode('; ', $parts)];
    }
}
