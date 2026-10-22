<?php

namespace ShibbirWeb\Peep\Bridge\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use ShibbirWeb\Peep\Bridge\LogRecord;
use ShibbirWeb\Peep\PeepFactory;

/**
 * Monolog 1 and 2 handler (records are arrays). For Monolog 3 use PeepLogRecordHandler,
 * or let PeepHandlerFactory::create() pick the right one.
 */
final class PeepHandler extends AbstractProcessingHandler
{
    /**
     * @var PeepFactory|null
     */
    private $factory;

    /**
     * @param int|string $level Lowest level to show.
     */
    public function __construct($level = 100, bool $bubble = true, ?PeepFactory $factory = null)
    {
        parent::__construct($level, $bubble);

        $this->factory = $factory;
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function write(array $record): void
    {
        LogRecord::send(
            $this->factory !== null ? $this->factory : PeepFactory::instance(),
            isset($record['level_name']) ? (string) $record['level_name'] : 'info',
            isset($record['message']) ? $record['message'] : '',
            isset($record['context']) && is_array($record['context']) ? $record['context'] : [],
            isset($record['channel']) ? (string) $record['channel'] : null
        );
    }
}
