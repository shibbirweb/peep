<?php

namespace ShibbirWeb\Peep\Support;

use Throwable;

/**
 * UUID version 4 without a dependency.
 */
final class Uuid
{
    private function __construct()
    {
    }

    public static function v4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (Throwable $exception) {
            // No secure random source: uniqueness is all a card id needs.
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
