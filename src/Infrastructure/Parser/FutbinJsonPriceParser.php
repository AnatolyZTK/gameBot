<?php

declare(strict_types=1);

namespace App\Infrastructure\Parser;

use App\Domain\Transfer\ValueObject\PlayerPriceQuote;

final class FutbinJsonPriceParser
{
    public function parse(string $json, int $futbinId, string $sourceUrl): ?PlayerPriceQuote
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $player = $data[(string) $futbinId] ?? $data[$futbinId] ?? null;
        if (!is_array($player)) {
            return null;
        }

        $prices = $player['prices'] ?? [];
        if (!is_array($prices)) {
            return null;
        }

        $name = is_string($player['name'] ?? null) ? $player['name'] : 'Player #'.$futbinId;

        return new PlayerPriceQuote(
            futbinId: $futbinId,
            name: $name,
            rating: isset($player['rating']) ? (int) $player['rating'] : null,
            position: is_string($player['position'] ?? null) ? $player['position'] : null,
            pricePs: $this->extractPlatformPrice($prices, 'ps'),
            priceXbox: $this->extractPlatformPrice($prices, 'xbox'),
            pricePc: $this->extractPlatformPrice($prices, 'pc'),
            sourceUrl: $sourceUrl,
        );
    }

    /**
     * @param array<string, mixed> $prices
     */
    private function extractPlatformPrice(array $prices, string $platform): ?int
    {
        $platformPrices = $prices[$platform] ?? null;
        if (!is_array($platformPrices)) {
            return null;
        }

        foreach (['LCPrice', 'LCPrice2', 'LCPrice3', 'MinPrice', 'maxPrice'] as $field) {
            if (!isset($platformPrices[$field])) {
                continue;
            }

            $parsed = $this->parsePriceValue($platformPrices[$field]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function parsePriceValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim(str_replace([',', ' '], '', $value)));
        if ($normalized === '' || $normalized === '0') {
            return null;
        }

        $multiplier = 1;
        if (str_ends_with($normalized, 'm')) {
            $multiplier = 1_000_000;
            $normalized = rtrim($normalized, 'm');
        } elseif (str_ends_with($normalized, 'k')) {
            $multiplier = 1_000;
            $normalized = rtrim($normalized, 'k');
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        $price = (int) round((float) $normalized * $multiplier);

        return $price > 0 ? $price : null;
    }
}
