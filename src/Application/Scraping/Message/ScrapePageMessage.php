<?php

declare(strict_types=1);

namespace App\Application\Scraping\Message;

final readonly class ScrapePageMessage
{
    public function __construct(
        public string $path,
    ) {
    }
}
