<?php

namespace ShibbirWeb\Peep\Support;

use Closure;

/**
 * Reads private and protected members without Reflection, so it never needs
 * setAccessible() (required on PHP 7, deprecated in 8.5).
 */
final class PrivateAccess
{
    private function __construct()
    {
    }

    /**
     * @param object $target
     * @return mixed
     */
    public static function property($target, string $property)
    {
        // A static closure scoped to the object's class may read its private members.
        $reader = static function ($object) use ($property) {
            return $object->{$property};
        };

        return Closure::bind($reader, null, get_class($target))($target);
    }

    /**
     * @param object $target
     * @param array<mixed> $arguments
     * @return mixed
     */
    public static function call($target, string $method, array $arguments)
    {
        $caller = static function ($object) use ($method, $arguments) {
            return $object->{$method}(...$arguments);
        };

        return Closure::bind($caller, null, get_class($target))($target);
    }
}
