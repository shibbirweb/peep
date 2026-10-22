<?php

namespace ShibbirWeb\Peep\Bridge\ErrorHandler;

use ErrorException;
use ShibbirWeb\Peep\Payload\ExceptionPayload;
use ShibbirWeb\Peep\PeepFactory;
use Throwable;

/**
 * For apps without a framework: shows PHP warnings, notices and deprecations, uncaught
 * exceptions and fatal errors in Peep. Every handler that was there before keeps running.
 *
 *     ErrorHandler::register();
 */
final class ErrorHandler
{
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private const LEVELS = [
        E_WARNING => 'warning',
        E_USER_WARNING => 'warning',
        E_NOTICE => 'notice',
        E_USER_NOTICE => 'notice',
        E_DEPRECATED => 'deprecation',
        E_USER_DEPRECATED => 'deprecation',
        E_RECOVERABLE_ERROR => 'error',
    ];

    /**
     * @var PeepFactory|null
     */
    private $factory;

    /**
     * @var callable|null
     */
    private $previousErrorHandler;

    /**
     * @var callable|null
     */
    private $previousExceptionHandler;

    /**
     * @var bool
     */
    private $handlingError = false;

    private function __construct(?PeepFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param bool $errors Warnings, notices and deprecations.
     * @param bool $exceptions Uncaught exceptions.
     * @param bool $fatal Fatal errors (checked on shutdown).
     */
    public static function register(bool $errors = true, bool $exceptions = true, bool $fatal = true, ?PeepFactory $factory = null): self
    {
        $handler = new self($factory);

        if ($errors) {
            $handler->previousErrorHandler = set_error_handler([$handler, 'handleError']);
        }

        if ($exceptions) {
            $handler->previousExceptionHandler = set_exception_handler([$handler, 'handleException']);
        }

        if ($fatal) {
            register_shutdown_function([$handler, 'handleShutdown']);
        }

        return $handler;
    }

    /**
     * @internal Called by PHP.
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // Errors raised while Peep itself reports one are not reported again.
        if (! $this->handlingError && isset(self::LEVELS[$level])) {
            $this->handlingError = true;

            try {
                $peep = $this->factory()->make();
                if ($peep->isActive()) {
                    $peep->payload('log_message', [
                        'level' => self::LEVELS[$level],
                        'message' => $message,
                        'context' => $this->factory()->services()->serializer->serialize([
                            'file' => $this->factory()->originResolver()->pathMapper()->map($file),
                            'line' => $line,
                            'silenced' => (error_reporting() & $level) === 0,
                        ]),
                    ])->color(self::LEVELS[$level] === 'deprecation' ? 'yellow' : 'orange');
                }
            } finally {
                $this->handlingError = false;
            }
        }

        if ($this->previousErrorHandler !== null) {
            return (bool) call_user_func($this->previousErrorHandler, $level, $message, $file, $line);
        }

        // Let PHP's own handling continue (display, log).
        return false;
    }

    /**
     * @internal Called by PHP.
     */
    public function handleException(Throwable $exception): void
    {
        $this->factory()->make()->exception($exception)->label('Uncaught exception');

        if ($this->previousExceptionHandler !== null) {
            call_user_func($this->previousExceptionHandler, $exception);

            return;
        }

        // Nothing else handles it: restore PHP's default output and rethrow.
        restore_exception_handler();

        throw $exception;
    }

    /**
     * @internal Called by PHP at shutdown.
     */
    public function handleShutdown(): void
    {
        $this->reportFatal(error_get_last());
    }

    /**
     * @internal
     * @param array<string, mixed>|null $error As returned by error_get_last().
     */
    public function reportFatal(?array $error): void
    {
        if ($error === null || ! in_array($error['type'], self::FATAL, true)) {
            return;
        }

        $peep = $this->factory()->make();
        if (! $peep->isActive()) {
            return;
        }

        $mapper = $this->factory()->originResolver()->pathMapper();
        $exception = new ErrorException((string) $error['message'], 0, (int) $error['type'], (string) $error['file'], (int) $error['line']);

        // PHP 8.5 adds the backtrace of fatal errors (fatal_error_backtraces). Older versions
        // have none, and the ErrorException's own trace would only show this shutdown handler.
        $trace = isset($error['trace']) && is_array($error['trace']) ? array_values($error['trace']) : [];

        $payload = new ExceptionPayload($exception, function (string $path) use ($mapper) {
            return $mapper->map($path);
        }, null, $trace);

        $peep->payload('exception', $payload->content())->color('red')->label('Fatal error');
    }

    private function factory(): PeepFactory
    {
        return $this->factory !== null ? $this->factory : PeepFactory::instance();
    }
}
