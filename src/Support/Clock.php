<?php

namespace ShibbirWeb\Peep\Support;

/**
 * Monotonic time in nanoseconds: hrtime() on PHP 7.3+, microtime() before.
 */
final class Clock
{
    private function __construct()
    {
    }

    public static function nanoseconds(): int
    {
        if (function_exists('hrtime')) {
            $time = hrtime(true); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.hrtimeFound
            if (is_int($time)) {
                return $time;
            }
        }

        return (int) (microtime(true) * 1000000000);
    }
}
