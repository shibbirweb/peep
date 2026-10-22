<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

/**
 * Turns one kind of framework activity (queries, jobs, events, ...) into Peep cards.
 */
interface WatcherInterface
{
    /**
     * Whether the Laravel version provides what this watcher listens to.
     */
    public function isSupported(): bool;

    /**
     * Attach the listeners. Called once, only when Peep is enabled and isSupported() is true.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function register($app): void;

    /**
     * Whether the watcher sends right now: config, overridden at runtime by show...() / stopShowing...().
     */
    public function isOn(): bool;
}
