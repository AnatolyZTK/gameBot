<?php

declare(strict_types=1);

namespace App\Domain\Transfer\ValueObject;

final readonly class PlayerPriceQuote
{
    public function __construct(
        public int $futbinId,
        public string $name,
        public ?int $rating,
        public ?string $position,
        public ?int $pricePs,
        public ?int $priceXbox,
        public ?int $pricePc,
        public string $sourceUrl,
    ) {
    }
}
