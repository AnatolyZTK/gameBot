<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Storage\S3;

use App\Infrastructure\Storage\S3\ScreenshotStorage;
use App\Tests\Support\TemporaryDirectory;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\TestCase;

final class ScreenshotStorageTest extends TestCase
{
    use TemporaryDirectory;

    private Filesystem $filesystem;

    private ScreenshotStorage $storage;

    /** @var string|false */
    private mixed $endpointBackup;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(
            new LocalFilesystemAdapter($this->createTemporaryDirectory('screenshots')),
        );
        $this->storage = new ScreenshotStorage($this->filesystem, 'app-storage');
        $this->endpointBackup = getenv('S3_ENDPOINT');
    }

    protected function tearDown(): void
    {
        putenv('S3_ENDPOINT'.(\is_string($this->endpointBackup) ? '='.$this->endpointBackup : ''));
        $this->removeTemporaryDirectories();
    }

    public function testStoreWritesUnderScreenshotsPrefixAndReturnsPath(): void
    {
        $path = $this->storage->store('login-error.png', 'binary-content');

        self::assertSame('screenshots/login-error.png', $path);
        self::assertSame('binary-content', $this->filesystem->read($path));
    }

    public function testLeadingSlashDoesNotDoubleThePrefix(): void
    {
        self::assertSame(
            'screenshots/nested/shot.png',
            $this->storage->store('/nested/shot.png', 'x'),
        );
    }

    public function testReadReturnsStoredContents(): void
    {
        $path = $this->storage->store('shot.png', 'содержимое');

        self::assertSame('содержимое', $this->storage->read($path));
    }

    public function testReadingMissingFileFails(): void
    {
        $this->expectException(UnableToReadFile::class);

        $this->storage->read('screenshots/absent.png');
    }

    public function testPublicUrlIsBuiltFromEndpointAndBucket(): void
    {
        putenv('S3_ENDPOINT=http://minio:9000/');

        self::assertSame(
            'http://minio:9000/app-storage/public/screenshots/shot.png',
            $this->storage->publicUrl('screenshots/shot.png'),
        );
        self::assertSame(
            'http://minio:9000/app-storage/public/screenshots/shot.png',
            $this->storage->publicUrl('/screenshots/shot.png'),
        );
    }
}
