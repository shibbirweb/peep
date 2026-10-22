<?php

namespace ShibbirWeb\Peep\Limiter;

use ShibbirWeb\Peep\Support\Uuid;

/**
 * Named counters for count(). Each counter keeps one card, so the viewer
 * updates "called 12 times" in place instead of adding a card per call.
 */
final class Counter
{
    /**
     * @var array<string, array{count: int, card: string}>
     */
    private $counters = [];

    /**
     * @return array{count: int, card: string} The new count and the counter's card id.
     */
    public function increment(string $name): array
    {
        if (! isset($this->counters[$name])) {
            $this->counters[$name] = ['count' => 0, 'card' => Uuid::v4()];
        }

        $this->counters[$name]['count']++;

        return $this->counters[$name];
    }

    public function value(string $name): int
    {
        return isset($this->counters[$name]) ? $this->counters[$name]['count'] : 0;
    }

    public function reset(): void
    {
        $this->counters = [];
    }
}
