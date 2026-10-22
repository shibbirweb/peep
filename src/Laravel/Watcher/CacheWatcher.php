<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Cache hits, misses, writes and deletes.
 */
final class CacheWatcher extends AbstractWatcher
{
    private const EVENTS = [
        'Illuminate\\Cache\\Events\\CacheHit' => 'hit',
        'Illuminate\\Cache\\Events\\CacheMissed' => 'missed',
        'Illuminate\\Cache\\Events\\KeyWritten' => 'written',
        'Illuminate\\Cache\\Events\\KeyForgotten' => 'forgotten',
    ];

    public function isSupported(): bool
    {
        return class_exists('Illuminate\\Cache\\Events\\CacheHit');
    }

    public function register($app): void
    {
        foreach (self::EVENTS as $class => $action) {
            $app->make('events')->listen($class, function ($event) use ($action) {
                $this->handle($event, $action);
            });
        }
    }

    /**
     * @param \Illuminate\Cache\Events\CacheEvent $event
     */
    public function handle($event, string $action): void
    {
        if (! $this->isOn()) {
            return;
        }

        try {
            $content = [
                'action' => $action,
                'key' => (string) $event->key,
                'store' => isset($event->storeName) ? $event->storeName : null,
            ];

            if ($action === 'hit' || $action === 'written') {
                $content['value'] = $this->serialize(isset($event->value) ? $event->value : null);
            }

            if ($action === 'written') {
                // Seconds since Laravel 5.8, minutes before.
                $content['ttl'] = isset($event->seconds) ? $event->seconds : (isset($event->minutes) ? $event->minutes * 60 : null);
            }

            $peep = $this->peep()->payload('cache', $content);

            if ($action === 'missed') {
                $peep->gray();
            }
        } catch (Throwable $exception) {
            // Never let Peep break caching.
        }
    }

    protected function configKey(): string
    {
        return 'send_cache';
    }
}
