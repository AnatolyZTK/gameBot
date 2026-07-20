<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\ElementClickInterceptedException;
use Facebook\WebDriver\Exception\ElementNotInteractableException;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client;
use Throwable;

/**
 * Chrome-клиент с маскировкой под обычный браузер.
 * EA FUT Web App блокирует headless и navigator.webdriver без доп. настроек.
 */
final class StealthChromeClientFactory
{
    public const EA_FUT_OAUTH_URL = 'https://accounts.ea.com/connect/auth?hide_create=true&display=web2%2Flogin&scope=basic.identity+offline+signin+basic.entitlement+basic.persona&release_type=prod&response_type=token&redirect_uri=https%3A%2F%2Fwww.ea.com%2Fea-sports-fc%2Fultimate-team%2Fweb-app%2Fauth.html&accessToken=&locale=en_US&prompt=login&client_id=FC26_JS_WEB_APP';

    private ?LocalHttpProxyRelay $proxyRelay = null;

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

    public function __construct(
        private readonly BrowserProfileSnapshotStorage $profileStorage,
    ) {
    }

    public function create(): Client
    {
        $id = bin2hex(random_bytes(6));

        return $this->createWithDirectories(
            userDataDir: '/tmp/panther-user-'.$id,
            crashDir: '/tmp/panther-crashes-'.$id,
        );
    }

    public function createForAccount(string $accountKey): Client
    {
        $this->profileStorage->repairPermissionsForWebServer();

        return $this->createWithDirectories(
            userDataDir: $this->profileStorage->getUserDataDirectory($accountKey),
            crashDir: $this->profileStorage->getCrashDumpsDirectory($accountKey),
        );
    }

    private function createWithDirectories(string $userDataDir, string $crashDir): Client
    {
        $this->ensureChromeEnvironment();

        @mkdir($userDataDir, 0777, true);
        @mkdir($crashDir, 0777, true);
        @chmod($userDataDir, 0777);
        @chmod($crashDir, 0777);

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

        $proxy = $this->resolveChromeProxyServer();
        if ($proxy !== null) {
            $arguments[] = '--proxy-server='.$proxy;
        }

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

    public function dismissBlockingOverlays(Client $client): void
    {
        $client->executeScript(<<<'JS'
document.querySelectorAll('.ui-orientation-warning').forEach((el) => el.remove());
document.querySelectorAll('.ut-click-shield.showing').forEach((el) => {
  el.classList.remove('showing');
  el.style.pointerEvents = 'none';
  el.style.display = 'none';
});
JS);
    }

    public function isClickShieldVisible(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
const shield = document.querySelector('.ut-click-shield.showing');
if (!shield) return false;

const style = getComputedStyle(shield);
return style.display !== 'none' && style.visibility !== 'hidden' && shield.offsetParent !== null;
JS);
    }

    public function waitForInteractive(Client $client, float $timeoutSeconds = 30.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (!$this->isClickShieldVisible($client)) {
                return;
            }

            usleep(250_000);
        }

        $this->dismissBlockingOverlays($client);
    }

    public function clickCssSelector(Client $client, string $cssSelector): void
    {
        $this->waitForInteractive($client);
        $this->dismissBlockingOverlays($client);

        $driver = $client->getWebDriver();

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $element = $driver->findElement(WebDriverBy::cssSelector($cssSelector));
            $driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$element]);

            try {
                $element->click();

                return;
            } catch (ElementClickInterceptedException|ElementNotInteractableException) {
                $this->dismissBlockingOverlays($client);
                $this->waitForInteractive($client, 5.0);
                usleep(500_000);
            }
        }

        $element = $driver->findElement(WebDriverBy::cssSelector($cssSelector));
        $driver->executeScript(<<<'JS'
const el = arguments[0];
['pointerdown', 'mousedown', 'mouseup', 'click'].forEach((type) => {
  el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, view: window }));
});
JS, [$element]);
    }

    public function openEaLoginPage(Client $client): string
    {
        $url = $this->resolveHttpsProxy() !== null
            ? self::EA_FUT_OAUTH_URL
            : $this->buildSigninEntryUrl();

        $client->request('GET', $url);
        usleep(2_000_000);
        $this->afterNavigation($client);

        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            $url = $client->getCurrentURL();
            if ($this->isEaLoginFlowUrl($url)) {
                return $url;
            }

            usleep(250_000);
        }

        throw new \RuntimeException('EA не открыла страницу входа: '.$client->getCurrentURL());
    }

    public function resolveEaLoginNavigationUrl(string $url): string
    {
        if ($this->resolveHttpsProxy() !== null && str_contains($url, 'accounts.ea.com')) {
            return $url;
        }

        if (str_contains($url, 'accounts.ea.com')) {
            return $this->buildSigninEntryUrlFromAccountsUrl($url);
        }

        return $url;
    }

    public function buildSigninEntryUrl(): string
    {
        return $this->buildSigninEntryUrlFromAccountsUrl(self::EA_FUT_OAUTH_URL);
    }

    public function buildSigninEntryUrlFromAccountsUrl(string $accountsUrl): string
    {
        $parts = parse_url($accountsUrl);
        $query = $parts['query'] ?? '';
        if ($query === '' && isset($parts['path']) && str_contains($parts['path'], 'connect/auth')) {
            $query = ltrim(str_replace('/connect/auth', '', $parts['path']), '?');
        }

        $initref = 'https://accounts.ea.com:443/connect/auth';
        if ($query !== '') {
            $initref .= '?'.$query;
        }

        return 'https://signin.ea.com/p/juno/login?initref='.rawurlencode($initref);
    }

    public function isEaAuthUrl(string $url): bool
    {
        return str_contains($url, 'signin.ea.com');
    }

    public function isEaLoginFlowUrl(string $url): bool
    {
        return str_contains($url, 'signin.ea.com')
            || str_contains($url, 'accounts.ea.com/connect/auth');
    }

    public function findAuthUrlInOpenWindows(Client $client, string $previousUrl): ?string
    {
        $driver = $client->getWebDriver();
        $mainHandle = $driver->getWindowHandle();

        try {
            foreach ($driver->getWindowHandles() as $handle) {
                $driver->switchTo()->window($handle);
                $currentUrl = $client->getCurrentURL();

                if ($this->isEaLoginFlowUrl($currentUrl)) {
                    return $this->resolveEaLoginNavigationUrl($currentUrl);
                }

                if ($currentUrl !== $previousUrl && str_starts_with($currentUrl, 'http')) {
                    return $currentUrl;
                }
            }
        } finally {
            try {
                $driver->switchTo()->window($mainHandle);
            } catch (Throwable) {
            }
        }

        return null;
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

    public function isFutAppAuthenticated(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
const login = document.querySelector('.ut-login-content');
const loginVisible = !!login
  && getComputedStyle(login).display !== 'none'
  && login.offsetParent !== null;

return !!document.querySelector('.ut-root-view') && !loginVisible;
JS);
    }

    public function isLoginScreenVisible(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
const login = document.querySelector('.ut-login-content');
if (!login) return false;

const style = getComputedStyle(login);
return style.display !== 'none' && style.visibility !== 'hidden' && login.offsetParent !== null;
JS);
    }

    /**
     * @return array{loginVisible: bool, authenticated: bool, hasRoot: bool, coins: ?string}
     */
    public function describeFutSessionState(Client $client): array
    {
        $state = $client->executeScript(<<<'JS'
const login = document.querySelector('.ut-login-content');
const loginStyle = login ? getComputedStyle(login) : null;
const loginVisible = !!login
  && loginStyle.display !== 'none'
  && loginStyle.visibility !== 'hidden'
  && login.offsetParent !== null;

return {
  loginVisible,
  authenticated: !!document.querySelector('.ut-root-view') && !loginVisible,
  hasRoot: !!document.querySelector('.ut-root-view'),
  coins: document.querySelector('.view-navbar-currency-coins')?.innerText?.trim() || null,
};
JS);

        if (!is_array($state)) {
            return [
                'loginVisible' => false,
                'authenticated' => false,
                'hasRoot' => false,
                'coins' => null,
            ];
        }

        return [
            'loginVisible' => (bool) ($state['loginVisible'] ?? false),
            'authenticated' => (bool) ($state['authenticated'] ?? false),
            'hasRoot' => (bool) ($state['hasRoot'] ?? false),
            'coins' => is_string($state['coins'] ?? null) ? $state['coins'] : null,
        ];
    }

    /**
     * EA сначала может кратко показать приложение, а затем экран логина.
     * Считаем сессию валидной только если авторизация держится stableSeconds подряд.
     */
    public function waitForStableSession(
        Client $client,
        float $stableSeconds = 8.0,
        float $timeoutSeconds = 45.0,
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;
        $stableSince = null;

        while (microtime(true) < $deadline) {
            if ($this->isFutAppAuthenticated($client)) {
                $stableSince ??= microtime(true);
                if (microtime(true) - $stableSince >= $stableSeconds) {
                    return true;
                }
            } else {
                $stableSince = null;
            }

            usleep(500_000);
        }

        return false;
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

    public function stopProxyRelay(): void
    {
        $this->proxyRelay?->stop();
        $this->proxyRelay = null;
    }

    private function resolveChromeProxyServer(): ?string
    {
        $raw = $this->resolveHttpsProxy();
        if ($raw === null) {
            return null;
        }

        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['host'])) {
            return $raw;
        }

        $username = $parts['user'] ?? null;
        $password = $parts['pass'] ?? null;
        if ($username !== null && $password !== null) {
            $this->stopProxyRelay();
            $this->proxyRelay = new LocalHttpProxyRelay();

            return $this->proxyRelay->start(
                $parts['host'],
                $parts['port'] ?? 8080,
                rawurldecode($username),
                rawurldecode($password),
            );
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $parts['host'], $port);
    }

    private function resolveHttpsProxy(): ?string
    {
        foreach (['EA_HTTPS_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function ensureChromeEnvironment(): void
    {
        $uid = \function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
        $chromiumHome = '/tmp/.chromium-'.$uid;

        $variables = [
            'HOME' => '/tmp',
            'XDG_CONFIG_HOME' => $chromiumHome,
            'XDG_CACHE_HOME' => $chromiumHome,
        ];

        foreach ($variables as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        @mkdir($chromiumHome, 0777, true);
        @chmod($chromiumHome, 0777);

        if ($uid === 0 && \function_exists('posix_getpwnam')) {
            $account = posix_getpwnam('www-data');
            if (is_array($account)) {
                @chown($chromiumHome, $account['name']);
                @chgrp($chromiumHome, $account['name']);
            }
        }
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
