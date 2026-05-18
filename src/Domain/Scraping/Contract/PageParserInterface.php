<?php

declare(strict_types=1);

namespace App\Domain\Scraping\Contract;

use App\Domain\Scraping\ValueObject\ScrapedItem;

interface PageParserInterface
{
    /**
     * @return list<ScrapedItem>
     */
    public function parse(string $html, string $sourceUrl): array;
}
