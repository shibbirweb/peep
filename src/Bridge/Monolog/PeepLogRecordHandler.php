<?php

namespace ShibbirWeb\Peep\Bridge\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord as MonologRecord;
use ShibbirWeb\Peep\Bridge\LogRecord;
use ShibbirWeb\Peep\PeepFactory;

/**
 * Monolog 3 handler (records are LogRecord objects). Only loaded when Monolog 3 is installed.
 */
final class PeepLogRecordHandler extends AbstractProcessingHandler
{
    /**
     * @var PeepFactory|null
     */
    private $factory;

    /**
     * @param int|string|\Monolog\Level $level Lowest level to show.
     * @phpstan-param value-of<\Monolog\Level::VALUES>|value-of<\Monolog\Level::NAMES>|\Monolog\Level|\Psr\Log\LogLevel::* $level
     */
    public function __construct($level = 100, bool $bubble = true, ?PeepFactory $factory = null)
    {
        parent::__construct(Logger::toMonologLevel($level), $bubble);

        $this->factory = $factory;
    }

    protected function write(MonologRecord $record): void
    {
        LogRecord::send(
            $this->factory !== null ? $this->factory : PeepFactory::instance(),
            $record->level->getName(),
            $record->message,
            $record->context,
            $record->channel
        );
    }
}
