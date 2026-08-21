<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use App\Infrastructure\Persistence\Entity\Account;
use Facebook\WebDriver\WebDriverBy;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Panther\Client;
use Throwable;

/**
 * Логин в EA FUT Web App по credentials аккаунта из БД.
 */
final class EaFutLoginService
{
    private const FUT_WEB_APP_URL = 'https://www.ea.com/ea-sports-fc/ultimate-team/web-app/';
    private const LOGIN_BUTTON = '.ut-login-content button.btn-standard.primary';
    private const SIGNIN_EMAIL = '#email';
    private const SIGNIN_EMAIL_NEXT = '#logInBtn';
    private const SIGNIN_PASSWORD = '#password';
    private const SIGNIN_PASSWORD_SUBMIT = '#logInBtn';
    private const SIGNIN_2FA_SEND_CODE = 'btnSendCode';
    private const SIGNIN_2FA_CODE = 'twoFactorCode';

    public function __construct(
        private readonly StealthChromeClientFactory $browserFactory,
        private readonly TotpCodeProvider $totpCodeProvider,
        private readonly BrowserProfileSnapshotStorage $profileStorage,
        #[Autowire(service: 'monolog.logger.scraping')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Открывает FUT и при необходимости выполняет полный вход. Профиль сохраняется.
     */
    public function ensureLoggedIn(Account $account): void
    {
        $email = $account->getEmail();
        $accountKey = $this->profileStorage->accountKeyFromEmail($email);
        $client = null;

        try {
            $client = $this->browserFactory->createForAccount($accountKey, $account->getProxyUrl());
            $this->browserFactory->prepare($client);
            $this->openFutWebApp($client);

            if ($this->browserFactory->waitForStableSession($client, 3.0, 45.0)) {
                $this->profileStorage->markSaved($accountKey, $email);
                $this->logger->info('EA session restored from profile', ['email' => $email]);

                return;
            }

            $this->logger->info('Performing full EA login', ['email' => $email]);
            $this->clickLoginAndFollowAuthUrl($client);
            $this->submitEmailAndFollowAuthUrl($client, $email);
            $this->fillPassword($client, $account->requirePassword());
            $postPasswordState = $this->submitPasswordAndOpenTwoFactor($client);

            if ($postPasswordState !== 'auth_complete') {
                $this->submitTwoFactorCode($client, $account->requireTotpSecret());
            }

            $this->waitForAuthCompletion($client);
            $this->stabilizeFutSessionAfterLogin($client);

            if (!$this->browserFactory->waitForStableSession($client, 8.0, 120.0)) {
                $state = $this->browserFactory->describeFutSessionState($client);
                throw new RuntimeException(sprintf(
                    'Не удалось подтвердить вход в EA. URL: %s. loginVisible=%s, authenticated=%s',
                    $client->getCurrentURL(),
                    $state['loginVisible'] ? 'true' : 'false',
                    $state['authenticated'] ? 'true' : 'false',
                ));
            }

            $this->profileStorage->markSaved($accountKey, $email);
            $this->logger->info('EA login completed', ['email' => $email]);
        } finally {
            try {
                $client?->quit();
            } catch (Throwable) {
            }

            $this->browserFactory->stopProxyRelay();
            $this->profileStorage->repairPermissionsForWebServer();

            if ($this->profileStorage->hasSnapshot($accountKey)) {
                $this->profileStorage->markSaved($accountKey, $email);
            }
        }
    }

    private function openFutWebApp(Client $client): string
    {
        $crawler = $client->request('GET', self::FUT_WEB_APP_URL);
        usleep(2_000_000);
        $this->browserFactory->afterNavigation($client);
        $this->browserFactory->waitForInteractive($client);

        $html = $crawler->html();
        if ($this->browserFactory->isUnsupportedBrowserPage($html)) {
            $client->reload();
            usleep(2_000_000);
            $this->browserFactory->afterNavigation($client);
            $html = $client->getCrawler()->html();
        }

        return $html;
    }

    private function clickLoginAndFollowAuthUrl(Client $client): string
    {
        $this->browserFactory->waitForInteractive($client);
        $this->browserFactory->dismissBlockingOverlays($client);

        if (!$this->isLoginButtonPresent($client)) {
            return $this->followLoginUrl(
                $client,
                $this->browserFactory->openEaLoginPage($client),
                $client->getCurrentURL(),
            );
        }

        $client->executeScript($this->loginUrlCaptureScript());
        $previousUrl = $client->getCurrentURL();

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->browserFactory->waitForInteractive($client);
                $this->browserFactory->dismissBlockingOverlays($client);

                if (!$this->isLoginButtonPresent($client)) {
                    break;
                }

                $this->browserFactory->clickCssSelector($client, self::LOGIN_BUTTON);
                $loginUrl = $this->tryResolveLoginUrl($client, $previousUrl, 15.0);
                if ($loginUrl !== null) {
                    return $this->followLoginUrl($client, $loginUrl, $previousUrl);
                }
            } catch (Throwable) {
            }

            usleep(1_000_000);
        }

        return $this->followLoginUrl(
            $client,
            $this->browserFactory->openEaLoginPage($client),
            $previousUrl,
        );
    }

    private function isLoginButtonPresent(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
const button = document.querySelector('.ut-login-content button.btn-standard.primary');
if (!button) return false;
const style = getComputedStyle(button);
return style.display !== 'none' && style.visibility !== 'hidden' && button.offsetParent !== null;
JS);
    }

    private function followLoginUrl(Client $client, string $loginUrl, string $previousUrl): string
    {
        $loginUrl = $this->browserFactory->resolveEaLoginNavigationUrl($loginUrl);
        if ($client->getCurrentURL() === $previousUrl) {
            $client->request('GET', $loginUrl);
        }
        $this->waitForEaSigninStep($client);

        return $loginUrl;
    }

    private function waitForEaSigninStep(Client $client): string
    {
        $deadline = microtime(true) + 45.0;
        while (microtime(true) < $deadline) {
            $this->assertEaSigninPageIsReady($client);
            $step = $client->executeScript(<<<'JS'
const email = document.getElementById('email');
const password = document.getElementById('password');
const isVisible = (el) => {
  if (!el) return false;
  const style = getComputedStyle(el);
  return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetParent !== null;
};
if (isVisible(email)) return 'email';
if (isVisible(password)) return 'password';
return null;
JS);
            if ($step === 'email' || $step === 'password') {
                return $step;
            }
            usleep(250_000);
        }

        $debugUrl = $client->getCurrentURL();
        $debugHtml = '';
        try {
            $debugHtml = mb_substr((string) $client->getCrawler()->html(), 0, 2000);
            $screenshotDir = $_ENV['PANTHER_ERROR_SCREENSHOT_DIR']
                ?? $_SERVER['PANTHER_ERROR_SCREENSHOT_DIR']
                ?? dirname(__DIR__, 3).'/var/screenshots';
            if (!str_starts_with((string) $screenshotDir, '/')) {
                $screenshotDir = dirname(__DIR__, 3).'/'.ltrim((string) $screenshotDir, './');
            }
            @mkdir($screenshotDir, 0777, true);
            @chmod($screenshotDir, 0777);
            $path = $screenshotDir.'/ea-login-timeout-'.date('His').'.png';
            $client->takeScreenshot($path);
            @chmod($path, 0666);
        } catch (\Throwable) {
        }
        $this->logger->error('EA login page timeout', ['url' => $debugUrl, 'html_snippet' => $debugHtml]);
        throw new RuntimeException('Страница входа EA не загрузилась: '.$debugUrl);
    }

    private function assertEaSigninPageIsReady(Client $client): void
    {
        $state = $client->executeScript(<<<'JS'
const body = document.body?.innerText ?? '';
return {
  unreachable: /can\x27t be reached|ERR_ADDRESS_UNREACHABLE|ERR_NO_SUPPORTED_PROXIES|ERR_PROXY|не удается получить доступ/i.test(body),
  proxyError: /ERR_NO_SUPPORTED_PROXIES|ERR_PROXY_CONNECTION_FAILED|ERR_TUNNEL_CONNECTION_FAILED/i.test(body),
  eaError: /something went wrong|technical difficulties|пошло не по плану|технические проблемы/i.test(body),
  snippet: body.replace(/\s+/g, ' ').trim().slice(0, 300),
};
JS);
        if (!is_array($state)) {
            return;
        }
        if ($state['proxyError'] ?? false) {
            $this->captureLoginDebug($client, 'proxy-error');
            throw new RuntimeException(
                'Прокси недоступен для Chrome (ERR_NO_SUPPORTED_PROXIES / proxy connection failed). '
                .'Проверьте proxy_url аккаунта: http://user:pass@host:port'
            );
        }
        if ($state['unreachable'] ?? false) {
            $this->captureLoginDebug($client, 'unreachable');
            throw new RuntimeException('accounts.ea.com недоступен (прокси или сеть?).');
        }
        if ($state['eaError'] ?? false) {
            $this->captureLoginDebug($client, 'ea-oauth-reject');
            $snippet = is_string($state['snippet'] ?? null) ? (string) $state['snippet'] : '';
            $this->logger->error('EA OAuth rejected', [
                'url' => $client->getCurrentURL(),
                'snippet' => $snippet,
            ]);
            throw new RuntimeException(
                'EA отклонила OAuth-вход. Частые причины: плохой прокси/IP, слишком много попыток, '
                .'битый browser-профиль. Скриншот: /admin/screenshots. '
                .'Попробуйте очистить профиль и сменить/убрать прокси.'
            );
        }
    }

    private function captureLoginDebug(Client $client, string $tag): void
    {
        try {
            $screenshotDir = $_ENV['PANTHER_ERROR_SCREENSHOT_DIR']
                ?? $_SERVER['PANTHER_ERROR_SCREENSHOT_DIR']
                ?? dirname(__DIR__, 3).'/var/screenshots';
            if (!str_starts_with((string) $screenshotDir, '/')) {
                $screenshotDir = dirname(__DIR__, 3).'/'.ltrim((string) $screenshotDir, './');
            }
            @mkdir($screenshotDir, 0777, true);
            @chmod($screenshotDir, 0777);
            $path = $screenshotDir.'/'.$tag.'-'.date('His').'.png';
            $client->takeScreenshot($path);
            @chmod($path, 0666);
        } catch (\Throwable) {
        }
    }

    private function tryResolveLoginUrl(Client $client, string $previousUrl, float $timeoutSeconds): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $captured = $client->executeScript('return window.__eaLoginUrl');
            if (is_string($captured) && $captured !== '') {
                return $this->browserFactory->resolveEaLoginNavigationUrl($captured);
            }
            $authUrl = $this->browserFactory->findAuthUrlInOpenWindows($client, $previousUrl);
            if ($authUrl !== null) {
                return $authUrl;
            }
            usleep(250_000);
        }

        return null;
    }

    private function submitEmailAndFollowAuthUrl(Client $client, string $email): string
    {
        if ($this->waitForEaSigninStep($client) === 'password') {
            $this->ensureCorrectEaAccount($client, $email);

            return $client->getCurrentURL();
        }

        $client->waitForVisibility(self::SIGNIN_EMAIL, 30);
        $driver = $client->getWebDriver();
        $emailField = $driver->findElement(WebDriverBy::cssSelector(self::SIGNIN_EMAIL));
        $emailField->clear();
        $emailField->sendKeys($email);

        $client->executeScript($this->loginUrlCaptureScript());
        $previousUrl = $client->getCurrentURL();
        $driver->findElement(WebDriverBy::cssSelector(self::SIGNIN_EMAIL_NEXT))->click();

        $passwordUrl = $this->waitForLoginUrl($client, $previousUrl);
        if ($client->getCurrentURL() === $previousUrl) {
            $client->request('GET', $this->browserFactory->resolveEaLoginNavigationUrl($passwordUrl));
        }
        $client->waitForVisibility(self::SIGNIN_PASSWORD, 30);

        return $passwordUrl;
    }

    private function fillPassword(Client $client, string $password): void
    {
        $client->waitForVisibility(self::SIGNIN_PASSWORD, 30);
        $driver = $client->getWebDriver();
        $passwordField = $driver->findElement(WebDriverBy::cssSelector(self::SIGNIN_PASSWORD));
        $passwordField->clear();
        $passwordField->sendKeys($password);
    }

    private function submitPasswordAndOpenTwoFactor(Client $client): string
    {
        $client->executeScript($this->loginUrlCaptureScript());
        $previousUrl = $client->getCurrentURL();
        $client->getWebDriver()
            ->findElement(WebDriverBy::cssSelector(self::SIGNIN_PASSWORD_SUBMIT))
            ->click();

        return $this->waitForPostPasswordStep($client, $previousUrl);
    }

    private function ensureCorrectEaAccount(Client $client, string $email): void
    {
        $displayedEmail = $client->executeScript(<<<'JS'
const emailField = document.getElementById('email');
if (emailField && emailField.value) return emailField.value;
const match = (document.body?.innerText ?? '').match(/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/);
return match ? match[0] : null;
JS);
        if (!is_string($displayedEmail) || strcasecmp($displayedEmail, $email) === 0) {
            return;
        }

        $client->executeScript(<<<'JS'
const logout = Array.from(document.querySelectorAll('a')).find((link) => /log out/i.test(link.innerText));
if (logout) logout.click();
JS);
        $this->waitForEaSigninStep($client);
    }

    private function waitForPostPasswordStep(Client $client, string $previousUrl): string
    {
        $deadline = microtime(true) + 45.0;
        $authenticatedSince = null;

        while (microtime(true) < $deadline) {
            if ($this->hasInvalidEaCredentials($client)) {
                throw new RuntimeException('Неверный пароль EA.');
            }

            if ($this->isTwoFactorSelectionPage($client)
                || $this->isTwoFactorCodePage($client)
                || $this->isEaTwoFactorTitle($client)) {
                return '2fa';
            }

            $url = $client->getCurrentURL();
            if (str_contains($url, 'auth.html')) {
                usleep(500_000);
                continue;
            }

            if (str_contains($url, 'ultimate-team/web-app')
                && $this->browserFactory->isFutAppAuthenticated($client)) {
                $authenticatedSince ??= microtime(true);
                if (microtime(true) - $authenticatedSince >= 2.0) {
                    return 'auth_complete';
                }
            } else {
                $authenticatedSince = null;
            }

            usleep(500_000);
        }

        throw new RuntimeException('Страница 2FA EA не загрузилась.');
    }

    private function hasInvalidEaCredentials(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
return /credentials are incorrect|have expired|invalid password/i.test(document.body?.innerText ?? '');
JS);
    }

    private function isTwoFactorSelectionPage(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
if (!location.hostname.includes('signin.ea.com')) return false;
const app = document.getElementById('APP');
if (!app) return false;
const style = getComputedStyle(app);
return style.display !== 'none' && style.visibility !== 'hidden' && app.offsetParent !== null;
JS);
    }

    private function isTwoFactorCodePage(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
const field = document.getElementById('twoFactorCode');
if (!field) return false;
return field.getBoundingClientRect().width > 10;
JS);
    }

    private function isEaTwoFactorTitle(Client $client): bool
    {
        return (bool) $client->executeScript('return /Two Factor Log In/i.test(document.title);');
    }

    private function submitTwoFactorCode(Client $client, string $secret): void
    {
        if (!$this->isTwoFactorCodePage($client)) {
            $this->selectAppAuthenticator($client);
            $this->openTwoFactorCodeForm($client);
        }

        $attemptsLeft = 3;
        while ($attemptsLeft-- > 0) {
            if ($attemptsLeft < 2) {
                $this->waitForFreshTotpWindow();
            }

            $code = $this->totpCodeProvider->generate($secret);
            $this->fillTwoFactorCode($client, $code);
            $this->trustThisDeviceIfPresent($client);
            $this->submitTwoFactorCodeForm($client);

            if ($this->waitForTwoFactorSuccess($client)) {
                return;
            }

            if ($attemptsLeft === 0) {
                throw new RuntimeException('EA отклонила код 2FA. Проверьте totp_secret аккаунта.');
            }
        }
    }

    private function stabilizeFutSessionAfterLogin(Client $client): void
    {
        $this->browserFactory->afterNavigation($client);
        if ($this->browserFactory->isLoginScreenVisible($client)) {
            $client->reload();
            usleep(3_000_000);
            $this->browserFactory->afterNavigation($client);
        }

        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            if ($this->browserFactory->isFutAppAuthenticated($client)
                || $this->browserFactory->isLoginScreenVisible($client)) {
                return;
            }
            usleep(500_000);
        }
    }

    private function waitForAuthCompletion(Client $client): void
    {
        $deadline = microtime(true) + 90.0;
        $sawAuthHtml = false;
        $authenticatedSince = null;

        while (microtime(true) < $deadline) {
            $url = $client->getCurrentURL();
            if (str_contains($url, 'signin.ea.com')) {
                usleep(500_000);
                continue;
            }
            if (str_contains($url, 'auth.html')) {
                $sawAuthHtml = true;
                $authenticatedSince = null;
                $this->browserFactory->afterNavigation($client);
                usleep(500_000);
                continue;
            }
            if (str_contains($url, 'ultimate-team/web-app')) {
                $this->browserFactory->afterNavigation($client);
                if ($sawAuthHtml) {
                    $authDeadline = microtime(true) + 45.0;
                    while (microtime(true) < $authDeadline) {
                        if ($this->browserFactory->isFutAppAuthenticated($client)) {
                            return;
                        }
                        usleep(500_000);
                    }
                }
                if ($this->browserFactory->isFutAppAuthenticated($client)) {
                    $authenticatedSince ??= microtime(true);
                    if (microtime(true) - $authenticatedSince >= 3.0) {
                        return;
                    }
                } else {
                    $authenticatedSince = null;
                }
                usleep(500_000);
                continue;
            }
            usleep(500_000);
        }

        throw new RuntimeException('EA не завершила OAuth после 2FA. URL: '.$client->getCurrentURL());
    }

    private function trustThisDeviceIfPresent(Client $client): void
    {
        $client->executeScript(<<<'JS'
for (const selector of ['#trustThisDevice', '#rememberMe', 'input[name="trustThisDevice"]', 'input[name="rememberMe"]']) {
  const checkbox = document.querySelector(selector);
  if (checkbox && !checkbox.checked) { checkbox.click(); break; }
}
JS);
    }

    private function waitForTwoFactorSuccess(Client $client): bool
    {
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if ($this->hasInvalidTwoFactorCode($client)) {
                return false;
            }
            $url = $client->getCurrentURL();
            if (str_contains($url, 'auth.html')) {
                return true;
            }
            if (str_contains($url, 'ultimate-team/web-app')
                && $this->browserFactory->isFutAppAuthenticated($client)) {
                return true;
            }
            usleep(500_000);
        }

        return false;
    }

    private function hasInvalidTwoFactorCode(Client $client): bool
    {
        return (bool) $client->executeScript(<<<'JS'
return /security code you entered is invalid/i.test(document.body?.innerText ?? '');
JS);
    }

    private function selectAppAuthenticator(Client $client): void
    {
        if (!$this->isTwoFactorSelectionPage($client)) {
            return;
        }
        $client->executeScript('document.getElementById("APP")?.click();');
        usleep(500_000);
    }

    private function openTwoFactorCodeForm(Client $client): void
    {
        if ($this->isTwoFactorCodePage($client)) {
            return;
        }
        $client->getWebDriver()->findElement(WebDriverBy::id(self::SIGNIN_2FA_SEND_CODE))->click();
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if ($this->isTwoFactorCodePage($client)) {
                return;
            }
            usleep(250_000);
        }
        throw new RuntimeException('Поле ввода кода 2FA не появилось');
    }

    private function fillTwoFactorCode(Client $client, string $code): void
    {
        $field = $client->getWebDriver()->findElement(WebDriverBy::id(self::SIGNIN_2FA_CODE));
        $field->clear();
        $field->sendKeys($code);
    }

    private function submitTwoFactorCodeForm(Client $client): void
    {
        $client->executeScript('document.getElementById("btnSubmit")?.click();');
    }

    private function waitForFreshTotpWindow(): void
    {
        $secondsLeft = 30 - (time() % 30);
        if ($secondsLeft < 5) {
            sleep($secondsLeft + 1);
        }
    }

    private function waitForLoginUrl(Client $client, string $previousUrl): string
    {
        $authUrl = $this->tryResolveLoginUrl($client, $previousUrl, 45.0);
        if ($authUrl === null) {
            throw new RuntimeException('Ссылка для входа не была сгенерирована');
        }

        return $authUrl;
    }

    private function loginUrlCaptureScript(): string
    {
        return <<<'JS'
window.__eaLoginUrl = null;
const capture = (url) => {
  if (!url) return;
  const value = String(url);
  if (value.startsWith('http://') || value.startsWith('https://')) {
    window.__eaLoginUrl = value;
  }
};
const open = window.open;
window.open = function(url, ...args) { capture(url); return open.apply(this, [url, ...args]); };
const assign = Location.prototype.assign;
Location.prototype.assign = function(url) { capture(url); return assign.call(this, url); };
const replace = Location.prototype.replace;
Location.prototype.replace = function(url) { capture(url); return replace.call(this, url); };
JS;
    }
}
