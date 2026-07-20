<?php

declare(strict_types=1);

namespace App\Application\Transfer\Message;

final readonly class ExecuteTransferMessage
{
    public function __construct(
        public string $transferId,
    ) {
    }
}
