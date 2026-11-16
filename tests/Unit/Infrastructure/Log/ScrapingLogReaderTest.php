<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Log;

use App\Infrastructure\Log\ScrapingLogReader;
use App\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ScrapingLogReaderTest extends TestCase
{
    use TemporaryDirectory;

    private ScrapingLogReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ScrapingLogReader($this->createTemporaryDirectory('scraping-log'));
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testPathPointsAtScrapingLogInsideVar(): void
    {
        self::assertStringEndsWith('/var/log/scraping.log', $this->reader->getPath());
    }

    public function testMissingLogIsReportedAsNonExistentInsteadOfFailing(): void
    {
        $result = $this->reader->readTail();

        self::assertFalse($result['exists']);
        self::assertSame([], $result['lines']);
        self::assertSame(0, $result['size']);
        self::assertSame(0, $result['offset']);
        self::assertNull($result['mtime']);
    }

    public function testReadsAllLinesAndReportsOffsetAtEndOfFile(): void
    {
        $path = $this->writeLog(["первая", "вторая", "третья"]);

        $result = $this->reader->readTail();

        self::assertTrue($result['exists']);
        self::assertSame(['первая', 'вторая', 'третья'], $result['lines']);
        self::assertSame((int) filesize($path), $result['offset']);
        self::assertNotNull($result['mtime']);
    }

    public function testReturnsOnlyTailWhenMaxLinesIsSmaller(): void
    {
        $this->writeLog(array_map(static fn (int $i): string => 'строка '.$i, range(1, 50)));

        $result = $this->reader->readTail(5);

        self::assertCount(5, $result['lines']);
        self::assertSame('строка 46', $result['lines'][0]);
        self::assertSame('строка 50', $result['lines'][4]);
    }

    public function testEmptyLinesAreDropped(): void
    {
        file_put_contents($this->logPath(), "первая\n\n\nвторая\n");

        self::assertSame(['первая', 'вторая'], $this->reader->readTail()['lines']);
    }

    public function testOffsetPollingReturnsOnlyNewLines(): void
    {
        $this->writeLog(['старая']);
        $offset = $this->reader->readTail()['offset'];

        file_put_contents($this->logPath(), "новая 1\nновая 2\n", \FILE_APPEND);
        $result = $this->reader->readTail(200, $offset);

        self::assertSame(['новая 1', 'новая 2'], $result['lines']);
        self::assertSame((int) filesize($this->logPath()), $result['offset']);
    }

    public function testOffsetAtEndOfFileReturnsNothingNew(): void
    {
        $this->writeLog(['строка']);
        $offset = $this->reader->readTail()['offset'];

        $result = $this->reader->readTail(200, $offset);

        self::assertSame([], $result['lines']);
        self::assertSame($offset, $result['offset']);
    }

    public function testOffsetBeyondFileSizeFallsBackToTail(): void
    {
        $this->writeLog(['единственная']);

        // Лог мог быть повёрнут/усечён: offset больше размера — читаем хвост заново
        $result = $this->reader->readTail(200, 1_000_000);

        self::assertSame(['единственная'], $result['lines']);
    }

    public function testMaxLinesIsClampedToTwoThousand(): void
    {
        $this->writeLog(array_map(static fn (int $i): string => 'строка '.$i, range(1, 2_100)));

        $result = $this->reader->readTail(5_000);

        self::assertCount(2_000, $result['lines']);
        self::assertSame('строка 2100', $result['lines'][1_999]);
    }

    public function testLargeLogIsReadFromChunkWithoutBrokenFirstLine(): void
    {
        $lines = array_map(
            static fn (int $i): string => sprintf('[2026-03-01T00:00:%02d] scraping.INFO: %s', $i % 60, str_repeat('x', 500)),
            range(1, 400),
        );
        $this->writeLog($lines);

        $result = $this->reader->readTail(10);

        self::assertCount(10, $result['lines']);
        foreach ($result['lines'] as $line) {
            self::assertStringStartsWith('[2026-03-01T00:00:', $line, 'Обрезанная первая строка не должна попадать в вывод');
        }
    }

    /** @param list<string> $lines */
    private function writeLog(array $lines): string
    {
        $path = $this->logPath();
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function logPath(): string
    {
        $path = $this->reader->getPath();
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }

        return $path;
    }
}
