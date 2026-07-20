<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Enum;

enum Platform: string
{
    case Ps = 'ps';
    case Xbox = 'xbox';
    case Pc = 'pc';

    public function label(): string
    {
        return match ($this) {
            self::Ps => 'PlayStation',
            self::Xbox => 'Xbox',
            self::Pc => 'PC',
        };
    }
}
