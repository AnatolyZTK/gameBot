<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Временные каталоги для тестов, которые работают с файловой системой.
 */
trait TemporaryDirectory
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function createTemporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('Не удалось создать временный каталог: '.$path);
        }

        $this->temporaryDirectories[] = $path;

        return $path;
    }

    protected function removeTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeRecursively($directory);
        }

        $this->temporaryDirectories = [];
    }

    private function removeRecursively(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeRecursively($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
