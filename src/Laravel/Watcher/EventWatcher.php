<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Every dispatched event, except the framework events listed in "ignore_events".
 */
final class EventWatcher extends AbstractWatcher
{
    public function register($app): void
    {
        $app->make('events')->listen('*', function ($event, $payload = []) {
            $this->handle($event, is_array($payload) ? $payload : [$payload]);
        });
    }

    /**
     * @param string|object $event Event name (Laravel 5.4+ wildcard listeners get the name).
     * @param array<mixed> $payload
     */
    public function handle($event, array $payload): void
    {
        $name = is_object($event) ? get_class($event) : (string) $event;

        if (! $this->isOn() || $this->ignored($name)) {
            return;
        }

        try {
            // Class-based events are dispatched with the event object as the only payload item.
            $data = count($payload) === 1 && isset($payload[0]) && is_object($payload[0]) ? $payload[0] : $payload;

            $this->peep()->payload('event', [
                'name' => $name,
                'payload' => $this->serialize($data),
            ]);
        } catch (Throwable $exception) {
            // Never let Peep break event dispatching.
        }
    }

    protected function configKey(): string
    {
        return 'send_events';
    }

    private function ignored(string $name): bool
    {
        $patterns = isset($this->config['ignore_events']) && is_array($this->config['ignore_events']) ? $this->config['ignore_events'] : [];

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && preg_match('/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/', $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
