<?php

namespace ShibbirWeb\Peep\Transport;

/**
 * Remembers that a viewer could not be reached, so the client stops trying for a while.
 *
 * Kept per process (statics survive in queue workers and long-running servers) and,
 * when APCu is enabled, shared between PHP-FPM requests.
 */
final class AvailabilityCache
{
    public const DOWN_SECONDS = 30;

    private const APCU_PREFIX = 'peep.viewer_down.';

    /**
     * @var array<string, float> endpoint => down until (unix time)
     */
    private static $downUntil = [];

    /**
     * @var int
     */
    private $downSeconds;

    /**
     * @var bool
     */
    private $useApcu;

    public function __construct(int $downSeconds = self::DOWN_SECONDS, ?bool $useApcu = null)
    {
        $this->downSeconds = $downSeconds;
        $this->useApcu = $useApcu !== null ? $useApcu : self::apcuAvailable();
    }

    public function isUp(string $endpoint): bool
    {
        $now = microtime(true);

        if (isset(self::$downUntil[$endpoint])) {
            if (self::$downUntil[$endpoint] > $now) {
                return false;
            }

            unset(self::$downUntil[$endpoint]);
        }

        if ($this->useApcu) {
            $until = apcu_fetch(self::APCU_PREFIX . $endpoint);
            if (is_float($until) && $until > $now) {
                self::$downUntil[$endpoint] = $until;

                return false;
            }
        }

        return true;
    }

    public function markDown(string $endpoint): void
    {
        $until = microtime(true) + $this->downSeconds;
        self::$downUntil[$endpoint] = $until;

        if ($this->useApcu) {
            apcu_store(self::APCU_PREFIX . $endpoint, $until, $this->downSeconds);
        }
    }

    public function markUp(string $endpoint): void
    {
        if (! isset(self::$downUntil[$endpoint]) && ! $this->useApcu) {
            return;
        }

        unset(self::$downUntil[$endpoint]);

        if ($this->useApcu) {
            apcu_delete(self::APCU_PREFIX . $endpoint);
        }
    }

    /**
     * Forget every endpoint (tests, or after the viewer was started).
     */
    public static function reset(): void
    {
        self::$downUntil = [];
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
