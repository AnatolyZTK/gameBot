<?php

declare(strict_types=1);

namespace App\Infrastructure\Search\Manticore;

use Manticoresearch\Client;

final readonly class ManticoreClientFactory
{
    public function __construct(
        private string $host,
        private int $port,
    ) {
    }

    public function create(): Client
    {
        return new Client([
            'host' => $this->host,
            'port' => $this->port,
        ]);
    }
}
