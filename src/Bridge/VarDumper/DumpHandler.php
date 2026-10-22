<?php

namespace ShibbirWeb\Peep\Bridge\VarDumper;

use ShibbirWeb\Peep\PeepFactory;

/**
 * Sends Symfony's dump() and dd() to Peep:
 *
 *     DumpHandler::register();
 *
 * With $passthrough (default), the dump still prints where it normally does.
 */
final class DumpHandler
{
    private const VAR_DUMPER = 'Symfony\\Component\\VarDumper\\VarDumper';

    private function __construct()
    {
    }

    public static function isAvailable(): bool
    {
        return class_exists(self::VAR_DUMPER);
    }

    /**
     * @param callable|null $shouldSend Decides per dump whether to send (e.g. a watcher switch).
     */
    public static function register(bool $passthrough = true, ?PeepFactory $factory = null, ?callable $shouldSend = null): bool
    {
        if (! self::isAvailable()) {
            return false;
        }

        $class = self::VAR_DUMPER;
        $previous = null;

        // Cards point at the line that called dump(), not at VarDumper itself
        // (VarDumper.php and Resources/functions/dump.php share the package directory).
        $file = (new \ReflectionClass($class))->getFileName();
        if (is_string($file)) {
            ($factory !== null ? $factory : PeepFactory::instance())->originResolver()->skip(dirname($file) . DIRECTORY_SEPARATOR);
        }

        $previous = $class::setHandler(function ($value, ...$rest) use ($class, $passthrough, $factory, $shouldSend, &$previous) {
            if ($shouldSend === null || $shouldSend()) {
                $peepFactory = $factory !== null ? $factory : PeepFactory::instance();
                $peepFactory->make()->send($value)->label('dump()');
            }

            if (! $passthrough) {
                return;
            }

            if ($previous !== null) {
                call_user_func($previous, $value, ...$rest);

                return;
            }

            // No earlier handler: let VarDumper print with its default handler, then put ours back.
            $ours = $class::setHandler(null);
            $class::dump($value);
            $class::setHandler($ours);
        });

        return true;
    }
}
