<?php

namespace ShibbirWeb\Peep\Envelope;

/**
 * Encodes an envelope to JSON. Never throws: returns null when the envelope cannot be encoded.
 */
final class EnvelopeEncoder
{
    public function encode(Envelope $envelope): ?string
    {
        $data = $envelope->toArray();

        $json = json_encode($data, self::flags());
        if (is_string($json)) {
            return $json;
        }

        // PHP 7.1 has no JSON_INVALID_UTF8_SUBSTITUTE: clean invalid UTF-8 and retry.
        array_walk_recursive($data, function (&$value) {
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                $value = self::toValidUtf8($value);
            }
        });

        $json = json_encode($data, self::flags());

        return is_string($json) ? $json : null;
    }

    private static function flags(): int
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        // Added in PHP 7.2; read through constant() so the code still parses and checks clean on 7.1.
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= (int) constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }

        return $flags;
    }

    private static function toValidUtf8(string $value): string
    {
        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (is_string($converted)) {
                return $converted;
            }
        }

        $cleaned = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value);

        return is_string($cleaned) ? $cleaned : '';
    }
}
