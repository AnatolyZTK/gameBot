<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use Facebook\WebDriver\WebDriverBy;
use RuntimeException;
use Symfony\Component\Panther\Client;
use Throwable;

/**
 * Получает 6-значный TOTP-код с https://www.browserscan.net/ru/2fa.
 */
final class BrowserScanTotpCodeProvider
{
    private const PAGE_URL = 'https://www.browserscan.net/ru/2fa';
    private const SECRET_INPUT = 'input[name="key"]';

    public function __construct(
        private readonly StealthChromeClientFactory $browserFactory,
    ) {
    }

    /**
     * Открывает BrowserScan, вводит секрет и возвращает текущий 6-значный код.
     *
     * @param Client|null $client существующий Panther-клиент (браузер не закрывается)
     */
    public function fetchCode(string $secretKey, ?Client $client = null): string
    {
        $secretKey = trim($secretKey);
        if ($secretKey === '') {
            throw new RuntimeException('2FA secret key is empty');
        }

        $ownsClient = $client === null;
        $client ??= $this->browserFactory->create();

        if ($ownsClient) {
            $this->browserFactory->prepare($client);
        }

        try {
            $client->request('GET', self::PAGE_URL.'#'.$secretKey);
            $this->fillSecretKey($client, $secretKey);

            return $this->waitForSixDigitCode($client);
        } finally {
            if ($ownsClient) {
                try {
                    $client->quit();
                } catch (Throwable) {
                }
            }
        }
    }

    private function fillSecretKey(Client $client, string $secretKey): void
    {
        $client->waitFor(self::SECRET_INPUT, 15);

        $driver = $client->getWebDriver();
        $input = $driver->findElement(WebDriverBy::cssSelector(self::SECRET_INPUT));
        $currentValue = $input->getAttribute('value') ?? '';

        if ($currentValue !== $secretKey) {
            $input->clear();
            $input->sendKeys($secretKey);
        }

        usleep(500_000);
    }

    private function waitForSixDigitCode(Client $client): string
    {
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $code = $client->executeScript(<<<'JS'
const code = Array.from(document.querySelectorAll('strong'))
  .map((element) => element.textContent.trim())
  .find((text) => /^\d{6}$/.test(text));

return code ?? null;
JS);

            if (is_string($code) && preg_match('/^\d{6}$/', $code)) {
                return $code;
            }

            usleep(250_000);
        }

        throw new RuntimeException('BrowserScan 2FA code was not generated in time');
    }
}
