<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

/**
 * The process that started the viewer (the desktop app), for `serve --exit-with=<pid>`:
 * when it is gone, after a crash or a force quit, the viewer stops too.
 */
final readonly class ParentProcess
{
    public function __construct(
        public int $pid,
    ) {}

    public function isRunning(): bool
    {
        if (function_exists('posix_kill')) {
            // Signal 0 checks that the process exists without sending anything.
            return posix_kill($this->pid, 0) || posix_get_last_error() === 1; // 1: EPERM, it exists
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = (string) @shell_exec('tasklist /FI "PID eq ' . $this->pid . '" /NH /FO CSV 2>NUL');

            return str_contains($output, '"' . $this->pid . '"');
        }

        // Without a way to check, assume it runs: the viewer then stops only on a signal.
        return true;
    }
}
