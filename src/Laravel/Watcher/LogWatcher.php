<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Log calls (Log::info(), logger(), ...). Messages that carry an exception are left to
 * the exception watcher when it is on.
 */
final class LogWatcher extends AbstractWatcher
{
    public const EVENT = 'Illuminate\\Log\\Events\\MessageLogged';

    private const COLORS = [
        'emergency' => 'red',
        'alert' => 'red',
        'critical' => 'red',
        'error' => 'red',
        'warning' => 'orange',
        'notice' => 'blue',
        'info' => 'blue',
        'debug' => 'gray',
    ];

    /**
     * @var ExceptionWatcher|null
     */
    private $exceptions;

    public function setExceptionWatcher(ExceptionWatcher $exceptions): void
    {
        $this->exceptions = $exceptions;
    }

    public function isSupported(): bool
    {
        return class_exists(self::EVENT);
    }

    public function register($app): void
    {
        $app->make('events')->listen(self::EVENT, function ($event) {
            $this->handle((string) $event->level, $event->message, is_array($event->context) ? $event->context : []);
        });
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public function handle(string $level, $message, array $context): void
    {
        if (! $this->isOn()) {
            return;
        }

        if (isset($context['exception']) && $context['exception'] instanceof Throwable && $this->exceptions !== null && $this->exceptions->isOn()) {
            return;
        }

        try {
            $peep = $this->peep()->payload('log_message', [
                'level' => $level,
                'message' => is_scalar($message) || (is_object($message) && method_exists($message, '__toString')) ? (string) $message : json_encode($message),
                'context' => $this->serialize($context),
            ]);

            if (isset(self::COLORS[$level])) {
                $peep->color(self::COLORS[$level]);
            }
        } catch (Throwable $exception) {
            // Never let Peep break logging.
        }
    }

    protected function configKey(): string
    {
        return 'send_logs';
    }
}
