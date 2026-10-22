<?php

namespace ShibbirWeb\Peep\Bridge\Psr;

use Psr\Log\LoggerInterface;
use ShibbirWeb\Peep\Bridge\LogRecord;
use ShibbirWeb\Peep\PeepFactory;

/**
 * A PSR-3 logger that shows every message in Peep:
 *
 *     $logger = new PeepLogger();
 *     $logger->warning('Disk almost full', ['free' => '2%']);
 *
 * One class for psr/log 1, 2 and 3: the message parameter is untyped (2 and 3 type it,
 * and leaving it untyped is allowed), and every method returns void (3 requires it).
 */
final class PeepLogger implements LoggerInterface
{
    /**
     * @var PeepFactory|null
     */
    private $factory;

    /**
     * @param PeepFactory|null $factory Defaults to the shared factory used by peep().
     */
    public function __construct(?PeepFactory $factory = null)
    {
        $this->factory = $factory;
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param mixed $level
     * @param mixed $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $factory = $this->factory !== null ? $this->factory : PeepFactory::instance();

        LogRecord::send($factory, is_scalar($level) ? (string) $level : 'info', $message, $context);
    }
}
