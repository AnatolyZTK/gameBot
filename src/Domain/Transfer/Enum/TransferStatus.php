<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Enum;

enum TransferStatus: string
{
    case Planned = 'planned';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
}
