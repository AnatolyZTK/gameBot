<?php

declare(strict_types=1);

namespace App\Infrastructure\Log;

/**
 * Чтение хвоста monolog scraping.log для админ-консоли.
 */
final class ScrapingLogReader
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getPath(): string
    {
        return $this->projectDir.'/var/log/scraping.log';
    }

    /**
     * @return array{
     *   path: string,
     *   exists: bool,
     *   size: int,
     *   mtime: ?int,
     *   lines: list<string>,
     *   offset: int
     * }
     */
    public function readTail(int $maxLines = 200, int $fromOffset = 0): array
    {
        $path = $this->getPath();
        if (!is_file($path)) {
            return [
                'path' => $path,
                'exists' => false,
                'size' => 0,
                'mtime' => null,
                'lines' => [],
                'offset' => 0,
            ];
        }

        $size = (int) filesize($path);
        $mtime = filemtime($path) ?: null;

        if ($fromOffset > 0 && $fromOffset <= $size) {
            return $this->readFromOffset($path, $fromOffset, $size, $mtime, $maxLines);
        }

        return $this->readLastLines($path, $size, $mtime, $maxLines);
    }

    /**
     * @return array{path: string, exists: bool, size: int, mtime: ?int, lines: list<string>, offset: int}
     */
    private function readLastLines(string $path, int $size, ?int $mtime, int $maxLines): array
    {
        $maxLines = max(1, min(2000, $maxLines));
        $chunk = min($size, max(32_768, $maxLines * 400));
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть '.$path);
        }

        try {
            if ($size > $chunk) {
                fseek($handle, -$chunk, SEEK_END);
                // первая строка может быть обрезана
                fgets($handle);
            }

            $content = stream_get_contents($handle);
            if ($content === false) {
                $content = '';
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n")) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return [
            'path' => $path,
            'exists' => true,
            'size' => $size,
            'mtime' => $mtime,
            'lines' => $lines,
            'offset' => $size,
        ];
    }

    /**
     * @return array{path: string, exists: bool, size: int, mtime: ?int, lines: list<string>, offset: int}
     */
    private function readFromOffset(string $path, int $fromOffset, int $size, ?int $mtime, int $maxLines): array
    {
        if ($fromOffset >= $size) {
            return [
                'path' => $path,
                'exists' => true,
                'size' => $size,
                'mtime' => $mtime,
                'lines' => [],
                'offset' => $size,
            ];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть '.$path);
        }

        try {
            fseek($handle, $fromOffset);
            $content = stream_get_contents($handle);
            if ($content === false) {
                $content = '';
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        // если файл не закончился на \n, последняя «строка» может быть неполной — отдадим её,
        // следующий poll дочитает с нового offset (size), так что неполные куски редки при append-only log
        if ($content !== '' && !str_ends_with($content, "\n") && !str_ends_with($content, "\r")) {
            // оставляем как есть
        } else {
            $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return [
            'path' => $path,
            'exists' => true,
            'size' => $size,
            'mtime' => $mtime,
            'lines' => $lines,
            'offset' => $size,
        ];
    }
}
