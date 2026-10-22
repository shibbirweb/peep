<?php

namespace ShibbirWeb\Peep\Bridge\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use ShibbirWeb\Peep\PeepFactory;

/**
 * The Peep handler for the installed Monolog version:
 *
 *     $logger->pushHandler(PeepHandlerFactory::create());
 */
final class PeepHandlerFactory
{
    private function __construct()
    {
    }

    /**
     * @param int|string $level Lowest level to show (100 = debug, or a name such as "warning").
     * @phpstan-param value-of<\Monolog\Level::VALUES>|value-of<\Monolog\Level::NAMES>|\Psr\Log\LogLevel::* $level
     */
    public static function create($level = 100, bool $bubble = true, ?PeepFactory $factory = null): AbstractProcessingHandler
    {
        // Monolog 3 has Logger::API = 3 and LogRecord objects; 1 and 2 pass arrays.
        if (defined('Monolog\\Logger::API') && constant('Monolog\\Logger::API') >= 3) {
            return new PeepLogRecordHandler($level, $bubble, $factory);
        }

        return new PeepHandler($level, $bubble, $factory);
    }
}
