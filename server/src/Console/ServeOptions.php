<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

use InvalidArgumentException;

/**
 * Options of `peep-server serve`.
 */
final readonly class ServeOptions
{
    public const string DEFAULT_HOST = '127.0.0.1';

    public const int DEFAULT_PORT = 23517;

    public function __construct(
        public string $host = self::DEFAULT_HOST,
        public int $port = self::DEFAULT_PORT,
        public OpenMode $open = OpenMode::Browser,
    ) {}

    /**
     * @param list<string> $arguments Command-line arguments after the command name.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArguments(array $arguments): self
    {
        $values = [];

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];

            if (preg_match('/^--(host|port|open)(?:=(.*))?$/', $argument, $matches) !== 1) {
                throw new InvalidArgumentException("Unknown option: {$argument}");
            }

            // Both "--port=9000" and "--port 9000".
            $values[$matches[1]] = $matches[2] ?? $arguments[++$index] ?? '';
        }

        $port = filter_var($values['port'] ?? self::DEFAULT_PORT, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new InvalidArgumentException('--port must be a number from 1 to 65535.');
        }

        $open = OpenMode::tryFrom($values['open'] ?? OpenMode::Browser->value)
            ?? throw new InvalidArgumentException('--open must be browser, window or none.');

        $host = trim($values['host'] ?? self::DEFAULT_HOST);
        if (! self::isLoopback($host)) {
            // Listening beyond this machine needs the access token (VF-20, Phase 6).
            throw new InvalidArgumentException('--host must be a loopback address (127.0.0.1, localhost or ::1) until token support is added.');
        }

        return new self($host, $port, $open);
    }

    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
