<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

use InvalidArgumentException;
use ShibbirWeb\PeepServer\Settings\Settings;

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
     * @param Settings $settings Host, port and display mode when the arguments do not set them.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArguments(array $arguments, Settings $settings = new Settings()): self
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

        $port = filter_var($values['port'] ?? $settings->port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new InvalidArgumentException('--port must be a number from 1 to 65535.');
        }

        $open = OpenMode::tryFrom($values['open'] ?? $settings->displayMode->value)
            ?? throw new InvalidArgumentException('--open must be browser, window or none.');

        $host = trim($values['host'] ?? $settings->host);
        if (! self::isLoopback($host) && $settings->token === null) {
            // Other machines could then send to (and with Docker on Linux, reach) the viewer.
            throw new InvalidArgumentException('Listening on ' . $host . ' needs a token. Set one in the Peep settings first, or use 127.0.0.1.');
        }

        return new self($host, $port, $open);
    }

    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
