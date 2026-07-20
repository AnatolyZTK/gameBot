<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser\Fut;

use App\Infrastructure\Browser\BrowserProfileSnapshotStorage;
use App\Infrastructure\Browser\StealthChromeClientFactory;

final class FutWebAppSessionFactory
{
    public function __construct(
        private readonly StealthChromeClientFactory $browserFactory,
        private readonly BrowserProfileSnapshotStorage $profileStorage,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(FutWebAppSession): T $callback
     *
     * @return T
     */
    public function withSession(string $email, callable $callback): mixed
    {
        $session = FutWebAppSession::open($this->browserFactory, $this->profileStorage, $email);

        try {
            return $callback($session);
        } finally {
            $session->close();
        }
    }
}
