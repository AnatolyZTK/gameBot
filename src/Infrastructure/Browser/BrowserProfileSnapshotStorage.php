<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use RuntimeException;

/**
 * Хранит слепок Chrome user-data-dir для каждого аккаунта (cookies, localStorage, сессия).
 */
final class BrowserProfileSnapshotStorage
{
    private const CHROME_LOCK_FILES = [
        'SingletonLock',
        'SingletonSocket',
        'SingletonCookie',
        'DevToolsActivePort',
    ];

    private const PROFILE_JSON_FILES = [
        'Local State',
        'Default/Preferences',
    ];

    public function __construct(
        private readonly string $profilesDir,
    ) {
    }

    public function accountKeyFromEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public function hasSnapshot(string $accountKey): bool
    {
        $userDataDir = $this->getProfileRoot($accountKey).'/user-data';

        return is_dir($userDataDir.'/Default') || is_dir($userDataDir.'/Profile 1');
    }

    public function getUserDataDirectory(string $accountKey): string
    {
        $dir = $this->getProfileRoot($accountKey).'/user-data';
        $this->ensureDirectory($dir);
        $this->prepareProfileForUse($dir);

        return $dir;
    }

    public function getCrashDumpsDirectory(string $accountKey): string
    {
        $dir = $this->getProfileRoot($accountKey).'/crashes';
        $this->ensureDirectory($dir);
        $this->makeWritable($dir);

        return $dir;
    }

    public function markSaved(string $accountKey, ?string $email = null): void
    {
        $profileRoot = $this->getProfileRoot($accountKey);
        $metaPath = $profileRoot.'/meta.json';
        $payload = [
            'account_key' => $accountKey,
            'email' => $email,
            'saved_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if (is_file($metaPath)) {
            $existing = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($existing)) {
                $payload['created_at'] = $existing['created_at'] ?? $payload['saved_at'];
            }
        } else {
            $payload['created_at'] = $payload['saved_at'];
        }

        file_put_contents(
            $metaPath,
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE),
        );

        $userDataDir = $profileRoot.'/user-data';
        if (is_dir($userDataDir)) {
            $this->makeWritable($userDataDir);
        }
    }

    public function repairPermissionsForWebServer(): void
    {
        if (!is_dir($this->profilesDir)) {
            return;
        }

        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $target = escapeshellarg($this->profilesDir);
            @exec('chown -R www-data:www-data '.$target.' 2>/dev/null');
            @exec('chmod -R a+rwX '.$target.' 2>/dev/null');
        }

        $this->makeWritable($this->profilesDir);
    }

    /**
     * Удаляет весь Chrome-профиль аккаунта (cookies / сессия EA).
     */
    public function deleteProfileForEmail(string $email): bool
    {
        $accountKey = $this->accountKeyFromEmail($email);
        $root = $this->getProfileRoot($accountKey);

        if (!is_dir($root)) {
            return false;
        }

        // Профиль мог создать worker от root — открыть права перед удалением
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $target = escapeshellarg($root);
            @exec('chmod -R a+rwX '.$target.' 2>/dev/null');
            @exec('rm -rf '.$target.' 2>/dev/null');

            return !is_dir($root);
        }

        @exec('chmod -R a+rwX '.escapeshellarg($root).' 2>/dev/null');
        $this->removeDirectory($root);

        if (is_dir($root)) {
            // Fallback: rm -rf (php-fpm может не удалить root-файлы)
            @exec('rm -rf '.escapeshellarg($root).' 2>/dev/null');
        }

        return !is_dir($root);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = @scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    private function getProfileRoot(string $accountKey): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $accountKey)) {
            throw new RuntimeException('Invalid browser profile account key');
        }

        return rtrim($this->profilesDir, '/').'/'.$accountKey;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $this->makeWritable($directory);
    }

    private function prepareProfileForUse(string $userDataDir): void
    {
        $this->removeChromeLocks($userDataDir);
        $this->repairCorruptedProfileFiles($userDataDir);
        $this->makeWritable($userDataDir);
    }

    private function repairCorruptedProfileFiles(string $userDataDir): void
    {
        foreach (self::PROFILE_JSON_FILES as $relativePath) {
            $path = $userDataDir.'/'.$relativePath;
            if (!is_file($path)) {
                continue;
            }

            if (!$this->isValidJsonFile($path)) {
                @unlink($path);
            }
        }
    }

    private function isValidJsonFile(string $path): bool
    {
        if (@filesize($path) === 0) {
            return false;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return false;
        }

        json_decode($contents);

        return json_last_error() === \JSON_ERROR_NONE;
    }

    private function removeChromeLocks(string $userDataDir): void
    {
        foreach (self::CHROME_LOCK_FILES as $lockFile) {
            $path = $userDataDir.'/'.$lockFile;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }

        $defaultDir = $userDataDir.'/Default';
        if (!is_dir($defaultDir)) {
            return;
        }

        foreach (self::CHROME_LOCK_FILES as $lockFile) {
            $path = $defaultDir.'/'.$lockFile;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }

    private function makeWritable(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        @chmod($directory, 0777);

        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($directory, 'www-data');
            @chgrp($directory, 'www-data');
        }

        $entries = @scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->makeWritable($path);

                continue;
            }

            if (is_file($path) || is_link($path)) {
                @chmod($path, 0666);

                if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    @chown($path, 'www-data');
                    @chgrp($path, 'www-data');
                }
            }
        }
    }
}
