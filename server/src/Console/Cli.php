<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

use ShibbirWeb\PeepServer\Version;

/**
 * Entry point of bin/peep-server: picks the command.
 */
final class Cli
{
    public const string USAGE = 'Usage: peep-server [serve] [--host=127.0.0.1] [--port=23517] [--open=browser|window|none] [--exit-with=PID]';

    private function __construct() {}

    /**
     * @param list<string> $arguments Arguments after the program name.
     */
    public static function run(array $arguments): int
    {
        $command = $arguments[0] ?? 'serve';

        if (str_starts_with($command, '--') && ! in_array($command, ['--version', '--help'], true)) {
            $command = 'serve';
        } else {
            array_shift($arguments);
        }

        switch ($command) {
            case 'serve':
                return new ServeCommand()->run($arguments);

            case 'version':
            case '--version':
                fwrite(STDOUT, 'Peep viewer ' . Version::VERSION . "\n");

                return 0;

            case 'help':
            case '--help':
                fwrite(STDOUT, self::USAGE . "\n");

                return 0;

            default:
                fwrite(STDERR, "Unknown command: {$command}\n" . self::USAGE . "\n");

                return 2;
        }
    }
}
