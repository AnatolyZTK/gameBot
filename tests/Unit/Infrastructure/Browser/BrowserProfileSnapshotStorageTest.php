<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Browser;

use App\Infrastructure\Browser\BrowserProfileSnapshotStorage;
use App\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BrowserProfileSnapshotStorageTest extends TestCase
{
    use TemporaryDirectory;

    private string $profilesDir;

    private BrowserProfileSnapshotStorage $storage;

    protected function setUp(): void
    {
        $this->profilesDir = $this->createTemporaryDirectory('browser-profiles');
        $this->storage = new BrowserProfileSnapshotStorage($this->profilesDir);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testAccountKeyIsNormalisedSha256OfEmail(): void
    {
        $expected = hash('sha256', 'player@example.com');

        self::assertSame($expected, $this->storage->accountKeyFromEmail('player@example.com'));
        self::assertSame($expected, $this->storage->accountKeyFromEmail('  Player@Example.COM  '));
    }

    public function testDifferentAccountsGetDifferentDirectories(): void
    {
        $first = $this->storage->getUserDataDirectory($this->storage->accountKeyFromEmail('a@example.com'));
        $second = $this->storage->getUserDataDirectory($this->storage->accountKeyFromEmail('b@example.com'));

        self::assertNotSame($first, $second);
        self::assertDirectoryExists($first);
        self::assertDirectoryExists($second);
        self::assertStringStartsWith($this->profilesDir.'/', $first);
        self::assertStringEndsWith('/user-data', $first);
    }

    public function testCrashDumpsDirectoryIsCreatedNextToProfile(): void
    {
        $key = $this->storage->accountKeyFromEmail('player@example.com');

        $crashDir = $this->storage->getCrashDumpsDirectory($key);

        self::assertDirectoryExists($crashDir);
        self::assertStringEndsWith('/crashes', $crashDir);
    }

    public function testInvalidAccountKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid browser profile account key');

        // Защита от path traversal: ключ обязан быть sha256-хешем
        $this->storage->getUserDataDirectory('../../etc');
    }

    public function testHasSnapshotOnlyAfterChromeCreatedProfileDirectory(): void
    {
        $key = $this->storage->accountKeyFromEmail('player@example.com');

        self::assertFalse($this->storage->hasSnapshot($key));

        $userData = $this->storage->getUserDataDirectory($key);
        self::assertFalse($this->storage->hasSnapshot($key), 'Пустой user-data — ещё не слепок сессии');

        mkdir($userData.'/Default', 0777, true);
        self::assertTrue($this->storage->hasSnapshot($key));
    }

    public function testStaleChromeLocksAreRemovedBeforeReuse(): void
    {
        $key = $this->storage->accountKeyFromEmail('player@example.com');
        $userData = $this->storage->getUserDataDirectory($key);
        mkdir($userData.'/Default', 0777, true);

        foreach (['SingletonLock', 'SingletonSocket', 'SingletonCookie', 'DevToolsActivePort'] as $lock) {
            file_put_contents($userData.'/'.$lock, 'x');
            file_put_contents($userData.'/Default/'.$lock, 'x');
        }

        $this->storage->getUserDataDirectory($key);

        foreach (['SingletonLock', 'SingletonSocket', 'SingletonCookie', 'DevToolsActivePort'] as $lock) {
            self::assertFileDoesNotExist($userData.'/'.$lock);
            self::assertFileDoesNotExist($userData.'/Default/'.$lock);
        }
    }

    public function testCorruptedProfileJsonIsDeletedButValidOneKept(): void
    {
        $key = $this->storage->accountKeyFromEmail('player@example.com');
        $userData = $this->storage->getUserDataDirectory($key);
        mkdir($userData.'/Default', 0777, true);

        file_put_contents($userData.'/Local State', '{"broken":');
        file_put_contents($userData.'/Default/Preferences', '{"valid":true}');

        $this->storage->getUserDataDirectory($key);

        self::assertFileDoesNotExist($userData.'/Local State', 'Битый JSON профиля должен удаляться');
        self::assertFileExists($userData.'/Default/Preferences');
    }

    public function testEmptyProfileJsonIsTreatedAsCorrupted(): void
    {
        $key = $this->storage->accountKeyFromEmail('player@example.com');
        $userData = $this->storage->getUserDataDirectory($key);
        file_put_contents($userData.'/Local State', '');

        $this->storage->getUserDataDirectory($key);

        self::assertFileDoesNotExist($userData.'/Local State');
    }

    public function testMarkSavedWritesMetaAndKeepsCreatedAtOnSecondCall(): void
    {
        $key = $this->storage->accountKeyFromEmail('player@example.com');
        $this->storage->getUserDataDirectory($key);

        $this->storage->markSaved($key, 'player@example.com');
        $metaPath = $this->profilesDir.'/'.$key.'/meta.json';
        self::assertFileExists($metaPath);

        $first = json_decode((string) file_get_contents($metaPath), true);
        self::assertSame($key, $first['account_key']);
        self::assertSame('player@example.com', $first['email']);
        self::assertSame($first['saved_at'], $first['created_at']);

        $this->storage->markSaved($key, 'player@example.com');
        $second = json_decode((string) file_get_contents($metaPath), true);

        self::assertSame($first['created_at'], $second['created_at'], 'created_at должен переноситься из прошлого meta.json');
    }

    public function testDeleteProfileRemovesEverythingForEmail(): void
    {
        $email = 'player@example.com';
        $key = $this->storage->accountKeyFromEmail($email);
        $userData = $this->storage->getUserDataDirectory($key);
        mkdir($userData.'/Default/Cache', 0777, true);
        file_put_contents($userData.'/Default/Cookies', 'session');
        $this->storage->markSaved($key, $email);

        self::assertTrue($this->storage->deleteProfileForEmail($email));
        self::assertDirectoryDoesNotExist($this->profilesDir.'/'.$key);
        self::assertFalse($this->storage->hasSnapshot($key));
    }

    public function testDeleteProfileReturnsFalseWhenNothingStored(): void
    {
        self::assertFalse($this->storage->deleteProfileForEmail('nobody@example.com'));
    }

    public function testRepairPermissionsIsSafeWhenProfilesDirectoryMissing(): void
    {
        $storage = new BrowserProfileSnapshotStorage($this->profilesDir.'/not-created-yet');

        $storage->repairPermissionsForWebServer();

        $this->expectNotToPerformAssertions();
    }
}
