<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

/**
 * PHP deprecation notices raised while the app runs. Chains the error handler that
 * was there before (Laravel's), so nothing else changes.
 */
final class DeprecationWatcher extends AbstractWatcher
{
    /**
     * @var callable|null
     */
    private $previous;

    public function register($app): void
    {
        // Only when configured: replacing the error handler is not worth it otherwise.
        if (! $this->configured()) {
            return;
        }

        $this->previous = set_error_handler(function ($level, $message, $file = '', $line = 0) {
            if (($level === E_DEPRECATED || $level === E_USER_DEPRECATED) && $this->isOn()) {
                $this->peep()->payload('log_message', [
                    'level' => 'deprecation',
                    'message' => (string) $message,
                    'context' => $this->serialize(['file' => $this->factory->originResolver()->pathMapper()->map((string) $file), 'line' => $line]),
                ])->yellow();
            }

            return $this->previous !== null ? call_user_func($this->previous, $level, $message, $file, $line) : false;
        });
    }

    protected function configKey(): string
    {
        return 'send_deprecations';
    }
}
