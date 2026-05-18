<?php

declare(strict_types=1);

namespace App\Application\Scraping\Message;

final readonly class ProcessParsedDataMessage
{
    public function __construct(
        public int $externalId,
        public string $title,
        public string $description,
        public string $category,
        public string $sourceUrl,
        public \DateTimeImmutable $scrapedAt,
    ) {
    }
}
