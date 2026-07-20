<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

/**
 * @phpstan-type TileData array{title: ?string, text: string, lines: list<string>}
 */
final readonly class EaFutDashboardData
{
    /**
     * @param list<TileData> $tiles
     */
    public function __construct(
        public ?string $coins,
        public array $tiles,
        public bool $authenticated,
        public ?string $error = null,
    ) {
    }

    public static function failure(string $error): self
    {
        return new self(null, [], false, $error);
    }
}
