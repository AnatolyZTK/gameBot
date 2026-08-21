<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser\Fut;

use App\Infrastructure\Browser\BrowserProfileSnapshotStorage;
use App\Infrastructure\Browser\StealthChromeClientFactory;
use App\Infrastructure\Persistence\Entity\Account;
use Symfony\Component\Panther\Client;

final class FutWebAppSession
{
    private const FUT_URL = 'https://www.ea.com/ea-sports-fc/ultimate-team/web-app/';

    private function __construct(
        private readonly Client $client,
        private readonly StealthChromeClientFactory $browserFactory,
        private readonly Account $account,
    ) {
    }

    public static function open(
        StealthChromeClientFactory $browserFactory,
        BrowserProfileSnapshotStorage $profileStorage,
        Account $account,
    ): self {
        $profileStorage->repairPermissionsForWebServer();
        $accountKey = $profileStorage->accountKeyFromEmail($account->getEmail());
        $client = $browserFactory->createForAccount($accountKey, $account->getProxyUrl());
        $browserFactory->prepare($client);
        $client->request('GET', self::FUT_URL);
        usleep(2_000_000);
        $browserFactory->afterNavigation($client);
        $browserFactory->waitForInteractive($client);

        if (!$browserFactory->waitForStableSession($client, 3.0, 90.0)) {
            $client->quit();
            $browserFactory->stopProxyRelay();

            throw new \RuntimeException(
                'FUT-сессия не авторизована для '.$account->getEmail()
                .'. Запустите: php bin/console app:account:login --email='.$account->getEmail()
            );
        }

        // Промо-модалки (Legacy Recap / Pre-order) часто всплывают сразу после входа
        for ($i = 0; $i < 5; ++$i) {
            $browserFactory->dismissBlockingOverlays($client);
            usleep(700_000);
        }

        return new self($client, $browserFactory, $account);
    }

    public function account(): Account
    {
        return $this->account;
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function factory(): StealthChromeClientFactory
    {
        return $this->browserFactory;
    }

    public function close(): void
    {
        try {
            $this->client->quit();
        } catch (\Throwable) {
        }

        $this->browserFactory->stopProxyRelay();
    }
}
