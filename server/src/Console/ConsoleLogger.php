<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Writes warnings and errors to STDERR; quieter levels only with --verbose.
 */
final class ConsoleLogger extends AbstractLogger
{
    private const array SHOWN_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
    ];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream = STDERR,
        private readonly bool $verbose = false,
    ) {}

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (! $this->verbose && ! in_array($level, self::SHOWN_LEVELS, true)) {
            return;
        }

        fwrite($this->stream, sprintf("[%s] %s\n", is_string($level) ? $level : 'log', (string) $message));
    }
}
