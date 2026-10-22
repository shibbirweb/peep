<?php

namespace ShibbirWeb\Peep\Tests\Fixture;

use RuntimeException;

/**
 * A stand-in viewer: PHP's built-in web server running fake-viewer.php, which
 * records every request to a log file and answers with a configurable status.
 */
final class FakeViewer
{
    /**
     * @var resource
     */
    private $process;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $logFile;

    private function __construct()
    {
    }

    public static function start(int $status = 202): self
    {
        $viewer = new self();
        $viewer->port = self::freePort();

        $logFile = tempnam(sys_get_temp_dir(), 'peep-fake-viewer-');
        if ($logFile === false) {
            throw new RuntimeException('Cannot create the fake viewer log file.');
        }
        $viewer->logFile = $logFile;

        $command = 'exec ' . escapeshellarg(PHP_BINARY)
            . ' -S ' . escapeshellarg('127.0.0.1:' . $viewer->port)
            . ' ' . escapeshellarg(__DIR__ . '/fake-viewer.php');

        $environment = array_merge(self::currentEnvironment(), [
            'PEEP_FAKE_VIEWER_LOG' => $logFile,
            'PEEP_FAKE_VIEWER_STATUS' => (string) $status,
        ]);

        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the fake viewer.');
        }
        $viewer->process = $process;

        $viewer->waitUntilListening();

        return $viewer;
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (! is_resource($server)) {
            throw new RuntimeException('Cannot find a free port: ' . $errorMessage);
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * @return list<array<string, mixed>> Each with method, path, content_type and body.
     */
    public function requests(): array
    {
        $requests = [];
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines === false ? [] : $lines as $line) {
            $request = json_decode($line, true);
            if (is_array($request)) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(20000);
        }

        $this->stop();

        throw new RuntimeException('The fake viewer did not start.');
    }

    /**
     * @return array<string, string>
     */
    private static function currentEnvironment(): array
    {
        $environment = [];
        foreach (array_merge($_SERVER, $_ENV) as $name => $value) {
            if (is_string($name) && is_string($value) && strpos($name, 'PEEP_') !== 0) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
