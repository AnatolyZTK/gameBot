<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Futbin;

use App\Infrastructure\Futbin\FutbinCookieProvider;
use App\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class FutbinCookieProviderTest extends TestCase
{
    use TemporaryDirectory;

    private string $cookieFile;

    /** @var string|false */
    private mixed $envBackup;

    protected function setUp(): void
    {
        $this->cookieFile = $this->createTemporaryDirectory('futbin-cookies').'/cookies.json';
        $this->envBackup = getenv('FUTBIN_COOKIES');
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        if (\is_string($this->envBackup)) {
            putenv('FUTBIN_COOKIES='.$this->envBackup);
        }

        $this->removeTemporaryDirectories();
    }

    public function testReturnsNullWhenNothingConfigured(): void
    {
        self::assertNull($this->provider()->getCookieHeader());
    }

    public function testEnvironmentCookiesWinOverStoredFile(): void
    {
        $this->provider()->save([['name' => 'from', 'value' => 'file']]);
        $this->setEnv('  from=env  ');

        self::assertSame('from=env', $this->provider()->getCookieHeader());
    }

    public function testBlankEnvironmentValueIsIgnored(): void
    {
        $this->provider()->save([['name' => 'from', 'value' => 'file']]);
        $this->setEnv('   ');

        self::assertSame('from=file', $this->provider()->getCookieHeader());
    }

    public function testSaveAndReadRoundTripBuildsCookieHeader(): void
    {
        $provider = $this->provider();

        $provider->save([
            ['name' => 'cf_clearance', 'value' => 'abc123', 'domain' => '.futbin.com'],
            ['name' => 'session', 'value' => 'xyz'],
        ]);

        self::assertFileExists($this->cookieFile);
        self::assertSame('cf_clearance=abc123; session=xyz', $provider->getCookieHeader());
    }

    public function testSaveCreatesMissingDirectory(): void
    {
        $nested = $this->createTemporaryDirectory('futbin-nested').'/deep/dir/cookies.json';
        $provider = new FutbinCookieProvider($nested);

        $provider->save([['name' => 'a', 'value' => 'b']]);

        self::assertFileExists($nested);
        self::assertSame('a=b', $provider->getCookieHeader());
    }

    public function testMalformedCookieEntriesAreSkipped(): void
    {
        file_put_contents($this->cookieFile, json_encode([
            ['name' => 'good', 'value' => 'yes'],
            ['name' => '', 'value' => 'empty name'],
            ['value' => 'no name'],
            ['name' => 'no value'],
            'просто строка',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame('good=yes', $this->provider()->getCookieHeader());
    }

    public function testReturnsNullWhenNoUsableCookiesInFile(): void
    {
        file_put_contents($this->cookieFile, json_encode([['name' => '', 'value' => '']], \JSON_THROW_ON_ERROR));

        self::assertNull($this->provider()->getCookieHeader());
    }

    public function testRawHeaderFileIsUsedAsIs(): void
    {
        // Файл может содержать не JSON, а готовую строку Cookie
        file_put_contents($this->cookieFile, "  cf_clearance=abc; session=xyz  \n");

        self::assertSame('cf_clearance=abc; session=xyz', $this->provider()->getCookieHeader());
    }

    public function testEmptyFileIsTreatedAsMissing(): void
    {
        file_put_contents($this->cookieFile, "  \n");

        self::assertNull($this->provider()->getCookieHeader());
    }

    private function provider(): FutbinCookieProvider
    {
        return new FutbinCookieProvider($this->cookieFile);
    }

    private function setEnv(string $value): void
    {
        $_ENV['FUTBIN_COOKIES'] = $value;
        $_SERVER['FUTBIN_COOKIES'] = $value;
        putenv('FUTBIN_COOKIES='.$value);
    }

    private function clearEnv(): void
    {
        unset($_ENV['FUTBIN_COOKIES'], $_SERVER['FUTBIN_COOKIES']);
        putenv('FUTBIN_COOKIES');
    }
}
