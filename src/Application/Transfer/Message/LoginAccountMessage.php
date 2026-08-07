<?php

declare(strict_types=1);

namespace App\Application\Transfer\Message;

final readonly class LoginAccountMessage
{
    public function __construct(
        public string $accountId,
    ) {
    }
}
