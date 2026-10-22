<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Support;

/**
 * Reads a value deep inside decoded JSON in tests.
 */
trait JsonPath
{
    /**
     * @param array<mixed> $data
     * @return mixed The value, or null when a key is missing.
     */
    private static function at(array $data, string|int ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
