<?php

declare(strict_types=1);

namespace App\Infrastructure\Parser;

use App\Domain\Scraping\Contract\PageParserInterface;
use App\Domain\Scraping\ValueObject\ScrapedItem;

final class GameSitePageParser implements PageParserInterface
{
    public function parse(string $html, string $sourceUrl): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, \LIBXML_NOERROR | \LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query('//*[@data-item-id]');
        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $items = [];
        $now = new \DateTimeImmutable();

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $externalId = (int) $node->getAttribute('data-item-id');
            $title = trim($xpath->evaluate('string(.//h2)', $node));
            $description = trim($xpath->evaluate('string(.//p[@class="description"])', $node));
            $category = trim($xpath->evaluate('string(.//span[@class="category"])', $node));

            if ($externalId <= 0 || $title === '') {
                continue;
            }

            $items[] = new ScrapedItem(
                externalId: $externalId,
                title: $title,
                description: $description,
                category: $category !== '' ? $category : 'unknown',
                sourceUrl: $sourceUrl.'#item-'.$externalId,
                scrapedAt: $now,
            );
        }

        return $items;
    }
}
