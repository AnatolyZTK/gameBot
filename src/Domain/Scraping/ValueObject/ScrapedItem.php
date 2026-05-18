<?php

declare(strict_types=1);

namespace App\Domain\Scraping\ValueObject;

final readonly class ScrapedItem
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
