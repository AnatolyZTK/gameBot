<?php

declare(strict_types=1);

namespace App\Application\Transfer\Message;

final readonly class PairTestMessage
{
    public function __construct(
        public string $senderId,
        public string $receiverId,
        public int $buyMax,
        public int $listPrice,
        public bool $buyAny = true,
        public bool $buyFirst = true,
        public ?string $playerName = null,
        public ?int $playerFutbinId = null,
    ) {
    }
}
