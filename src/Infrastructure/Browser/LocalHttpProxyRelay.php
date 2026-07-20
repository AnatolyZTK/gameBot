<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Локальный HTTP-прокси без авторизации для Chrome; upstream — прокси с Basic Auth.
 */
final class LocalHttpProxyRelay
{
    private const READY_PREFIX = 'READY ';

    private const STARTUP_TIMEOUT_SECONDS = 15.0;

    private ?Process $process = null;

    private int $port = 0;

    public function start(string $upstreamHost, int $upstreamPort, string $username, string $password): string
    {
        $this->stop();

        $script = __DIR__.'/Scripts/local-http-proxy-relay.php';
        if (!is_file($script)) {
            throw new \RuntimeException('Не найден скрипт локального прокси: '.$script);
        }

        $this->process = new Process([
            $this->resolvePhpCliBinary(),
            $script,
            '0',
            $upstreamHost,
            (string) $upstreamPort,
            $username,
            $password,
        ]);
        $this->process->setTimeout(null);
        $this->port = 0;

        $this->process->start(function (string $type, string $buffer): void {
            if ($this->port !== 0) {
                return;
            }

            if (preg_match('/'.preg_quote(self::READY_PREFIX, '/').'(\d+)/', $buffer, $matches) === 1) {
                $this->port = (int) $matches[1];
            }
        });

        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if ($this->port !== 0) {
                return 'http://127.0.0.1:'.$this->port;
            }

            if (!$this->process->isRunning()) {
                break;
            }

            usleep(20_000);
        }

        $errorOutput = trim($this->process->getErrorOutput()."\n".$this->process->getOutput());
        $this->stop();

        throw new \RuntimeException(
            'Локальный прокси не поднялся за '.(int) self::STARTUP_TIMEOUT_SECONDS.' секунд.'
            .($errorOutput !== '' ? ' '.$errorOutput : ''),
        );
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }

        $this->process = null;
        $this->port = 0;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function resolvePhpCliBinary(): string
    {
        foreach (['PHP_CLI_BINARY', 'EA_PHP_CLI_BINARY'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (is_string($value) && $value !== '' && is_executable($value)) {
                return $value;
            }
        }

        $binary = \PHP_BINARY;
        if (!str_contains(basename($binary), 'fpm') && is_executable($binary)) {
            return $binary;
        }

        foreach (['/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $found = (new ExecutableFinder())->find('php');
        if (is_string($found) && $found !== '' && !str_contains(basename($found), 'fpm') && is_executable($found)) {
            return $found;
        }

        throw new \RuntimeException(
            'Не найден PHP CLI для локального прокси. Задайте EA_PHP_CLI_BINARY=/usr/local/bin/php в .env.',
        );
    }
}
