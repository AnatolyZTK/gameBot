<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Panther\Client;

$client = Client::createChromeClient();

try {
    $client->request('GET', 'https://example.com');
    echo 'Title: ' . $client->getTitle() . PHP_EOL;
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
    throw $e;
} finally {
    try {
        $client->quit();
    } catch (\Throwable) {
        // ignore
    }
}

