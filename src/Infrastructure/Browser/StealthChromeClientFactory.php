<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Symfony\Component\Panther\Client;

/**
 * Chrome-клиент с маскировкой под обычный браузер.
 * EA FUT Web App блокирует headless и navigator.webdriver без доп. настроек.
 */
final class StealthChromeClientFactory
{
    private const STEALTH_JS = <<<'JS'
(() => {
  if (window.__stealthApplied) return;
  window.__stealthApplied = true;
  const define = (obj, prop, value) => {
    try { Object.defineProperty(obj, prop, { get: () => value }); } catch (e) {}
  };
  define(Navigator.prototype, 'webdriver', undefined);
  define(navigator, 'language', 'ru-RU');
  define(navigator, 'languages', ['ru-RU', 'ru', 'en-US', 'en']);
  define(navigator, 'hardwareConcurrency', 8);
  define(navigator, 'deviceMemory', 8);
  define(navigator, 'maxTouchPoints', 0);
  window.chrome = window.chrome || { runtime: {}, loadTimes: function() {}, csi: function() {}, app: {} };
  define(navigator, 'plugins', [
    { name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
    { name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai', description: '' },
    { name: 'Native Client', filename: 'internal-nacl-plugin', description: '' },
  ]);
})();
JS;

    public function create(): Client
    {
        $id = bin2hex(random_bytes(6));
        $userDataDir = '/tmp/panther-user-'.$id;
        $crashDir = '/tmp/panther-crashes-'.$id;
        @mkdir($userDataDir, 0700, true);
        @mkdir($crashDir, 0700, true);

        $arguments = [
            '--no-sandbox',
            '--disable-dev-shm-usage',
            // EA unsupported.js требует WebGL; в Docker без GPU — SwiftShader
            '--use-gl=angle',
            '--use-angle=swiftshader',
            '--enable-unsafe-swiftshader',
            '--disable-blink-features=AutomationControlled',
            '--exclude-switches=enable-automation',
            '--window-size=1920,1080',
            '--start-maximized',
            '--lang=ru-RU,ru',
            '--user-data-dir='.$userDataDir,
            '--crash-dumps-dir='.$crashDir,
        ];

        // Headed + xvfb надёжнее для EA: PANTHER_NO_HEADLESS=1
        if (!$this->isHeadlessDisabled()) {
            $arguments[] = '--headless=new';
        }

        return Client::createChromeClient(
            chromeDriverBinary: null,
            arguments: $arguments,
            options: [
                'host' => '127.0.0.1',
                'port' => $this->allocatePort(),
            ],
        );
    }

    public function prepare(Client $client): void
    {
        $client->request('GET', 'about:blank');
        $this->injectStealthOnNewDocuments($client);
        $this->emulateWindowsChrome($client);
        $client->executeScript(self::STEALTH_JS);
    }

    public function afterNavigation(Client $client): void
    {
        $client->executeScript(self::STEALTH_JS);
    }

    public function isUnsupportedBrowserPage(string $html): bool
    {
        if (str_contains($html, "browser we don't support")) {
            return true;
        }

        // unsupportedBody появляется в DOM только при срабатывании unsupported.js
        return str_contains($html, 'id="unsupportedBody"')
            && !str_contains($html, 'class="ut-root-view"');
    }

    private function injectStealthOnNewDocuments(Client $client): void
    {
        $devtools = $this->getDevTools($client);
        if ($devtools === null) {
            return;
        }

        $devtools->execute('Page.addScriptToEvaluateOnNewDocument', [
            'source' => self::STEALTH_JS,
        ]);
    }

    private function emulateWindowsChrome(Client $client): void
    {
        $devtools = $this->getDevTools($client);
        if ($devtools === null) {
            return;
        }

        $chromeVersion = $this->resolveChromeVersion();
        $userAgent = sprintf(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%s Safari/537.36',
            $chromeVersion,
        );

        $devtools->execute('Network.setUserAgentOverride', [
            'userAgent' => $userAgent,
            'acceptLanguage' => 'ru-RU,ru',
            'platform' => 'Win32',
            'userAgentMetadata' => [
                'brands' => [
                    ['brand' => 'Google Chrome', 'version' => explode('.', $chromeVersion)[0]],
                    ['brand' => 'Chromium', 'version' => explode('.', $chromeVersion)[0]],
                    ['brand' => 'Not.A/Brand', 'version' => '99'],
                ],
                'fullVersionList' => [
                    ['brand' => 'Google Chrome', 'version' => $chromeVersion],
                    ['brand' => 'Chromium', 'version' => $chromeVersion],
                    ['brand' => 'Not.A/Brand', 'version' => '99.0.0.0'],
                ],
                'fullVersion' => $chromeVersion,
                'platform' => 'Windows',
                'platformVersion' => '10.0.0',
                'architecture' => 'x86',
                'model' => '',
                'mobile' => false,
            ],
        ]);
    }

    private function getDevTools(Client $client): ?ChromeDevToolsDriver
    {
        $driver = $client->getWebDriver();
        if (!$driver instanceof RemoteWebDriver) {
            return null;
        }

        return new ChromeDevToolsDriver($driver);
    }

    private function resolveChromeVersion(): string
    {
        $raw = shell_exec('google-chrome --version 2>/dev/null') ?: '';
        if (preg_match('/(\d+\.\d+\.\d+\.\d+)/', $raw, $m)) {
            return $m[1];
        }

        return '131.0.0.0';
    }

    private function isHeadlessDisabled(): bool
    {
        return filter_var(
            $_SERVER['PANTHER_NO_HEADLESS'] ?? $_ENV['PANTHER_NO_HEADLESS'] ?? false,
            \FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function allocatePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($server)) {
            throw new \RuntimeException(sprintf('Unable to allocate port: %s', $errstr ?: (string) $errno));
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr(strrchr((string) $name, ':'), 1);
    }
}
