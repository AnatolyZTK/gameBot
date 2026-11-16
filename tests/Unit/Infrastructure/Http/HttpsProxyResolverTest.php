<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\HttpsProxyResolver;
use PHPUnit\Framework\TestCase;

final class HttpsProxyResolverTest extends TestCase
{
    private const VARIABLES = ['FUTBIN_HTTPS_PROXY', 'EA_HTTPS_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY'];

    /** @var array<string, string|false> */
    private array $backup = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->backup[$name] = getenv($name);
            $this->unsetVariable($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->unsetVariable($name);
            if (\is_string($this->backup[$name])) {
                $this->setVariable($name, $this->backup[$name]);
            }
        }
    }

    public function testReturnsNullWhenNoProxyConfigured(): void
    {
        self::assertNull((new HttpsProxyResolver())->resolve());
    }

    public function testFutbinProxyWinsOverEaAndGenericVariables(): void
    {
        $this->setVariable('HTTP_PROXY', 'http://generic:8080');
        $this->setVariable('HTTPS_PROXY', 'http://generic-https:8080');
        $this->setVariable('EA_HTTPS_PROXY', 'http://ea:8080');
        $this->setVariable('FUTBIN_HTTPS_PROXY', 'http://futbin:8080');

        self::assertSame('http://futbin:8080', (new HttpsProxyResolver())->resolve());
    }

    public function testEaProxyWinsOverGenericVariables(): void
    {
        $this->setVariable('HTTPS_PROXY', 'http://generic-https:8080');
        $this->setVariable('EA_HTTPS_PROXY', 'http://ea:8080');

        self::assertSame('http://ea:8080', (new HttpsProxyResolver())->resolve());
    }

    public function testFallsBackToHttpProxy(): void
    {
        $this->setVariable('HTTP_PROXY', 'http://generic:8080');

        self::assertSame('http://generic:8080', (new HttpsProxyResolver())->resolve());
    }

    public function testEmptyValuesAreIgnored(): void
    {
        $this->setVariable('FUTBIN_HTTPS_PROXY', '');
        $this->setVariable('EA_HTTPS_PROXY', '');
        $this->setVariable('HTTPS_PROXY', 'http://generic-https:8080');

        self::assertSame('http://generic-https:8080', (new HttpsProxyResolver())->resolve());
    }

    private function setVariable(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    private function unsetVariable(string $name): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }
}
