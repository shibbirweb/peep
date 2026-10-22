<?php

namespace ShibbirWeb\Peep\Control;

/**
 * Capture switches set in the viewer (for example "queries": on, off or default).
 *
 * Values arrive with every envelope response and are kept for the TTL the viewer gives.
 * "default" means: follow the app's own configuration.
 */
final class Switches
{
    public const ON = 'on';
    public const OFF = 'off';
    public const DEFAULT_STATE = 'default';

    /**
     * @var array<string, mixed>
     */
    private $values = [];

    /**
     * @var float
     */
    private $expiresAt = 0.0;

    /**
     * Reads switches from a viewer answer (envelope response or GET /_peep/switches).
     *
     * @param array<string, mixed> $answer
     */
    public function update(array $answer): void
    {
        if (! isset($answer['switches']) || ! is_array($answer['switches'])) {
            return;
        }

        $ttl = isset($answer['switches_ttl']) && is_int($answer['switches_ttl']) ? $answer['switches_ttl'] : 5;

        $values = [];
        foreach ($answer['switches'] as $name => $value) {
            if (is_string($name)) {
                $values[$name] = $value;
            }
        }

        $this->values = $values;
        $this->expiresAt = microtime(true) + max(1, min($ttl, 300));
    }

    public function isFresh(): bool
    {
        return microtime(true) < $this->expiresAt;
    }

    /**
     * @return string "on", "off" or "default".
     */
    public function state(string $name): string
    {
        $value = isset($this->values[$name]) ? $this->values[$name] : null;

        return $value === self::ON || $value === self::OFF ? $value : self::DEFAULT_STATE;
    }

    public function intValue(string $name): ?int
    {
        return isset($this->values[$name]) && is_int($this->values[$name]) ? $this->values[$name] : null;
    }

    public function expire(): void
    {
        $this->expiresAt = 0.0;
    }
}
