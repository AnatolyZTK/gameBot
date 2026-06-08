<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Infrastructure\Browser\StealthChromeClientFactory;
use Facebook\WebDriver\WebDriverBy;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Panther\Client;
use Throwable;

#[AsCommand(
    name: 'app:scrape:ea',
    description: 'Парсинг EA SPORTS FC Ultimate Team Web App через stealth-браузер',
)]
final class TestCommand extends Command
{
    private const FUT_WEB_APP_URL = 'https://www.ea.com/ea-sports-fc/ultimate-team/web-app/';
    private const LOGIN_BUTTON = 'button.btn-standard.primary';
    private const SIGNIN_EMAIL = '#email';
    private const SIGNIN_EMAIL_NEXT = '#logInBtn';
    private const SIGNIN_PASSWORD = '#password';

    public function __construct(
        private readonly StealthChromeClientFactory $browserFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = null;

        try {
            $client = $this->browserFactory->create();
            $this->browserFactory->prepare($client);

            $html = $this->openFutWebApp($client);
            if ($this->browserFactory->isUnsupportedBrowserPage($html)) {
                $io->error(
                    'EA определила браузер как неподдерживаемый (нет WebGL/WebAssembly). '
                    .'Убедитесь, что Chrome запускается с --use-angle=swiftshader в Docker.'
                );

                return Command::FAILURE;
            }

            $client->waitFor('.ut-login-content', 30);
            $loginUrl = $this->clickLoginAndFollowAuthUrl($client);
            $io->note('Переход на страницу входа EA: '.$loginUrl);

            $email = $this->resolveCredential('EA_LOGIN_EMAIL');
            $passwordUrl = $this->submitEmailAndFollowAuthUrl($client, $email);
            $io->note('Переход на шаг пароля: '.$passwordUrl);

            $password = $this->resolveCredential('EA_LOGIN_PASSWORD');
            $this->fillPassword($client, $password);
            $io->success('Email и пароль введены на странице входа EA');

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            try {
                $client?->quit();
            } catch (Throwable) {
            }
        }
    }

    private function openFutWebApp(Client $client): string
    {
        $crawler = $client->request('GET', self::FUT_WEB_APP_URL);
        usleep(2_000_000);
        $this->browserFactory->afterNavigation($client);

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
        $this->dismissBlockingOverlays($client);
        $client->waitForVisibility(self::LOGIN_BUTTON, 15);

        if (0 === $client->getCrawler()->filter(self::LOGIN_BUTTON)->count()) {
            throw new RuntimeException('Кнопка Login (.btn-standard.primary) не найдена');
        }

        $client->executeScript($this->loginUrlCaptureScript());
        $previousUrl = $client->getCurrentURL();

        $driver = $client->getWebDriver();
        $button = $driver->findElement(WebDriverBy::cssSelector(self::LOGIN_BUTTON));
        $driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$button]);
        $button->click();

        $loginUrl = $this->waitForLoginUrl($client, $previousUrl);

        if ($client->getCurrentURL() === $previousUrl) {
            $client->request('GET', $loginUrl);
        }

        $client->waitFor(self::SIGNIN_EMAIL, 30);

        return $loginUrl;
    }

    private function submitEmailAndFollowAuthUrl(Client $client, string $email): string
    {
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
            $client->request('GET', $passwordUrl);
        }

        $client->waitForVisibility(self::SIGNIN_PASSWORD, 30);

        return $passwordUrl;
    }

    private function fillPassword(Client $client, string $password): void
    {
        $driver = $client->getWebDriver();
        $passwordField = $driver->findElement(WebDriverBy::cssSelector(self::SIGNIN_PASSWORD));
        $passwordField->clear();
        $passwordField->sendKeys($password);
    }

    private function resolveCredential(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Задайте переменную окружения %s', $name));
        }

        return $value;
    }

    private function waitForLoginUrl(Client $client, string $previousUrl): string
    {
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            $captured = $client->executeScript('return window.__eaLoginUrl');
            if (is_string($captured) && $captured !== '') {
                return $captured;
            }

            $currentUrl = $client->getCurrentURL();
            if ($currentUrl !== $previousUrl && str_starts_with($currentUrl, 'http')) {
                return $currentUrl;
            }

            usleep(250_000);
        }

        throw new RuntimeException('Ссылка для входа не была сгенерирована после клика по Login');
    }

    private function dismissBlockingOverlays(Client $client): void
    {
        $client->executeScript(
            'document.querySelectorAll(".ui-orientation-warning").forEach(el => el.remove());',
        );
    }

    private function loginUrlCaptureScript(): string
    {
        return <<<'JS'
window.__eaLoginUrl = window.__eaLoginUrl ?? null;
const capture = (url) => {
  if (!url) return;
  const value = String(url);
  if (value.startsWith('http://') || value.startsWith('https://')) {
    window.__eaLoginUrl = value;
  }
};
if (!window.__eaLoginCaptureInstalled) {
  window.__eaLoginCaptureInstalled = true;
  const open = window.open;
  window.open = function(url, ...args) { capture(url); return open.apply(this, [url, ...args]); };
  const assign = Location.prototype.assign;
  Location.prototype.assign = function(url) { capture(url); return assign.call(this, url); };
  const replace = Location.prototype.replace;
  Location.prototype.replace = function(url) { capture(url); return replace.call(this, url); };
}
JS;
    }
}
