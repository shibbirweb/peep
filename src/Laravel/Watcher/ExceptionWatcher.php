<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Exceptions reported by the app: Laravel logs every reported exception with the
 * exception in the log context, so the log event is the hook.
 */
final class ExceptionWatcher extends AbstractWatcher
{
    public function isSupported(): bool
    {
        return class_exists(LogWatcher::EVENT);
    }

    public function register($app): void
    {
        $app->make('events')->listen(LogWatcher::EVENT, function ($event) {
            $context = is_array($event->context) ? $event->context : [];

            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                $this->handle($context['exception']);
            }
        });
    }

    public function handle(Throwable $exception): void
    {
        if (! $this->isOn()) {
            return;
        }

        $this->peep()->exception($exception)->label('Reported exception');
    }

    protected function configKey(): string
    {
        return 'send_exceptions';
    }
}
