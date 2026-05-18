<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage\S3;

use League\Flysystem\FilesystemOperator;

final readonly class ScreenshotStorage
{
    public function __construct(
        private FilesystemOperator $defaultStorage,
        private string $bucket,
    ) {
    }

    public function store(string $relativePath, string $contents): string
    {
        $path = 'screenshots/'.ltrim($relativePath, '/');
        $this->defaultStorage->write($path, $contents);

        return $path;
    }

    public function read(string $path): string
    {
        return $this->defaultStorage->read($path);
    }

    public function publicUrl(string $path): string
    {
        return sprintf('%s/%s/public/%s', rtrim((string) getenv('S3_ENDPOINT'), '/'), $this->bucket, ltrim($path, '/'));
    }
}
