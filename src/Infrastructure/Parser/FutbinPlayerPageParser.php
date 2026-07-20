<?php

declare(strict_types=1);

namespace App\Infrastructure\Parser;

use App\Domain\Transfer\ValueObject\PlayerPriceQuote;
use Symfony\Component\DomCrawler\Crawler;

final class FutbinPlayerPageParser
{
    public function parse(string $html, string $sourceUrl): ?PlayerPriceQuote
    {
        $futbinId = $this->extractFutbinId($sourceUrl, $html);
        if ($futbinId === null) {
            return null;
        }

        if (preg_match('/"LCPrice"\s*:\s*"?(\\d+)"?/i', $html, $matches) === 1) {
            return new PlayerPriceQuote(
                futbinId: $futbinId,
                name: $this->extractNameFromHtml($html) ?? 'Player #'.$futbinId,
                rating: $this->extractRatingFromHtml($html),
                position: $this->extractPositionFromHtml($html),
                pricePs: $this->extractEmbeddedPlatformPrice($html, 'ps'),
                priceXbox: $this->extractEmbeddedPlatformPrice($html, 'xbox'),
                pricePc: $this->extractEmbeddedPlatformPrice($html, 'pc'),
                sourceUrl: $sourceUrl,
            );
        }

        $crawler = new Crawler($html);

        $name = $this->extractName($crawler);
        if ($name === null || $name === '') {
            return null;
        }

        return new PlayerPriceQuote(
            futbinId: $futbinId,
            name: $name,
            rating: $this->extractRating($crawler),
            position: $this->extractPosition($crawler),
            pricePs: $this->extractPlatformPrice($crawler, 'ps'),
            priceXbox: $this->extractPlatformPrice($crawler, 'xbox'),
            pricePc: $this->extractPlatformPrice($crawler, 'pc'),
            sourceUrl: $sourceUrl,
        );
    }

    private function extractFutbinId(string $sourceUrl, string $html): ?int
    {
        if (preg_match('#/player/(\d+)#', $sourceUrl, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/data-player-id="(\d+)"/', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractNameFromHtml(string $html): ?string
    {
        if (preg_match('/<h1[^>]*>([^<]+)</', $html, $matches) === 1) {
            return trim(html_entity_decode($matches[1]));
        }

        return null;
    }

    private function extractRatingFromHtml(string $html): ?int
    {
        if (preg_match('/class="pcdisplay-rat"[^>]*>(\d+)</', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractPositionFromHtml(string $html): ?string
    {
        if (preg_match('/class="pcdisplay-pos"[^>]*>([^<]+)</', $html, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractEmbeddedPlatformPrice(string $html, string $platform): ?int
    {
        if (preg_match('/"'.$platform.'"\s*:\s*\{[^}]*"LCPrice"\s*:\s*"?(\\d+)"?/is', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractName(Crawler $crawler): ?string
    {
        foreach (['.playercard-26-name', '.player-card-name', 'h1.page-header-top', 'h1'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $name = trim($node->text());
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return null;
    }

    private function extractRating(Crawler $crawler): ?int
    {
        foreach (['.pcdisplay-rat', '.playercard-26-rating', '.player-card-rating'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $rating = (int) preg_replace('/\D/', '', $node->text());

                return $rating > 0 ? $rating : null;
            }
        }

        return null;
    }

    private function extractPosition(Crawler $crawler): ?string
    {
        foreach (['.pcdisplay-pos', '.playercard-26-position', '.player-card-position'] as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $position = trim($node->text());

                return $position !== '' ? $position : null;
            }
        }

        return null;
    }

    private function extractPlatformPrice(Crawler $crawler, string $platform): ?int
    {
        $selectors = [
            sprintf('.price.%s .price-value', $platform),
            sprintf('.platform-%s .price', $platform),
            sprintf('[data-platform="%s"] .price', $platform),
            sprintf('.player-price-%s', $platform),
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $price = $this->parsePrice($node->text());
                if ($price !== null) {
                    return $price;
                }
            }
        }

        if (preg_match(
            sprintf('#"%s"\s*:\s*(\d+)#i', preg_quote($platform, '#')),
            $crawler->html(),
            $matches,
        ) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function parsePrice(string $raw): ?int
    {
        $normalized = strtolower(trim($raw));
        if ($normalized === '' || str_contains($normalized, 'n/a')) {
            return null;
        }

        $multiplier = 1;
        if (str_contains($normalized, 'm')) {
            $multiplier = 1_000_000;
        } elseif (str_contains($normalized, 'k')) {
            $multiplier = 1_000;
        }

        if (preg_match('/([\d.,]+)/', $normalized, $matches) !== 1) {
            return null;
        }

        $number = (float) str_replace(',', '', $matches[1]);
        $price = (int) round($number * $multiplier);

        return $price > 0 ? $price : null;
    }
}
