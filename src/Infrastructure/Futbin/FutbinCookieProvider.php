<?php

declare(strict_types=1);

namespace App\Infrastructure\Futbin;

final class FutbinCookieProvider
{
    public function __construct(
        private readonly string $cookieFile,
    ) {
    }

    public function getCookieHeader(): ?string
    {
        $fromEnv = $_ENV['FUTBIN_COOKIES'] ?? $_SERVER['FUTBIN_COOKIES'] ?? getenv('FUTBIN_COOKIES');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        if (!is_file($this->cookieFile)) {
            return null;
        }

        $raw = file_get_contents($this->cookieFile);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return trim($raw);
        }

        $parts = [];
        foreach ($decoded as $cookie) {
            if (!is_array($cookie)) {
                continue;
            }

            $name = $cookie['name'] ?? null;
            $value = $cookie['value'] ?? null;
            if (is_string($name) && is_string($value) && $name !== '') {
                $parts[] = $name.'='.$value;
            }
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * @param list<array{name: string, value: string, domain?: string, path?: string}> $cookies
     */
    public function save(array $cookies): void
    {
        $dir = \dirname($this->cookieFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $this->cookieFile,
            json_encode($cookies, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE),
        );
        @chmod($this->cookieFile, 0666);
    }
}
