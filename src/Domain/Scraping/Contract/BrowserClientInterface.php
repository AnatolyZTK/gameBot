<?php

declare(strict_types=1);

namespace App\Domain\Scraping\Contract;

interface BrowserClientInterface
{
    public function fetch(string $path): string;

    public function post(string $path, array $payload = []): string;
}
