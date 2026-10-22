<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Console\HealthProbe;
use ShibbirWeb\PeepServer\Console\PortStatus;

/**
 * bin/peep-server as a separate process, as users run it.
 */
final class ServeCommandTest extends TestCase
{
    private const string BINARY = __DIR__ . '/../../bin/peep-server';

    private string $configDirectory;

    protected function setUp(): void
    {
        // The started viewers must not read or write the developer's own settings and history.
        $this->configDirectory = sys_get_temp_dir() . '/peep-serve-' . bin2hex(random_bytes(6));
        putenv('PEEP_CONFIG_DIR=' . $this->configDirectory);
    }

    protected function tearDown(): void
    {
        putenv('PEEP_CONFIG_DIR');

        foreach (glob($this->configDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->configDirectory)) {
            rmdir($this->configDirectory);
        }
    }

    public function testStartsDetectsItselfAndStopsOnSigint(): void
    {
        if (! function_exists('posix_kill') && ! function_exists('proc_terminate')) {
            $this->markTestSkipped('Cannot signal processes here.');
        }

        $port = self::freePort();
        $probe = new HealthProbe();
        $this->assertSame(PortStatus::Free, $probe->check('127.0.0.1', $port));

        $process = proc_open(
            [PHP_BINARY, '-d', 'zend.assertions=-1', self::BINARY, 'serve', "--port={$port}", '--open=none'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            $this->waitFor(static fn(): bool => $probe->check('127.0.0.1', $port) === PortStatus::Peep);

            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BINARY) . " serve --port={$port} --open=none", $output, $exitCode);
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('already running', implode("\n", $output));

            proc_terminate($process, 2);
            $this->waitFor(static fn(): bool => ! proc_get_status($process)['running']);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }

            proc_close($process);
        }

        $this->assertSame(PortStatus::Free, $probe->check('127.0.0.1', $port));
    }

    public function testReportsAPortUsedByAnotherProgram(): void
    {
        $port = self::freePort();

        // PHP's built-in web server: answers HTTP, but not as Peep.
        $other = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', sys_get_temp_dir()], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($other);

        try {
            $probe = new HealthProbe();
            $this->waitFor(static fn(): bool => $probe->check('127.0.0.1', $port) !== PortStatus::Free);
            $this->assertSame(PortStatus::Other, $probe->check('127.0.0.1', $port));

            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BINARY) . " serve --port={$port} --open=none 2>&1", $output, $exitCode);
            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('used by another program', implode("\n", $output));
        } finally {
            proc_terminate($other);
            proc_close($other);
        }
    }

    private function waitFor(callable $condition, float $seconds = 5.0): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(50_000);
        }

        $this->fail('Timed out waiting.');
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        assert(is_resource($server));
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
