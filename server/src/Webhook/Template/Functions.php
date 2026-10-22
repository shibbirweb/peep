<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook\Template;

/**
 * The functions templates may call. Each takes and returns plain values.
 */
final class Functions
{
    public const array NAMES = [
        'uuid', 'random_int', 'random_hex', 'hmac_sha256', 'hmac_sha1', 'sha1', 'sha256',
        'md5', 'base64', 'url_encode', 'date', 'upper', 'lower', 'json',
    ];

    private function __construct() {}

    public static function exists(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /**
     * @param list<mixed> $arguments
     */
    public static function call(string $name, array $arguments): mixed
    {
        $text = static fn(int $index): string => self::text($arguments[$index] ?? '');
        $int = static fn(int $index, int $default): int => is_numeric($arguments[$index] ?? null) ? (int) $arguments[$index] : $default;
        $hexLength = max(1, min(4096, $int(0, 32)));

        return match ($name) {
            'uuid' => self::uuid(),
            'random_int' => random_int($int(0, 0), max($int(0, 0), $int(1, PHP_INT_MAX))),
            'random_hex' => substr(bin2hex(random_bytes(max(1, intdiv($hexLength + 1, 2)))), 0, $hexLength),
            'hmac_sha256' => hash_hmac('sha256', $text(1), $text(0)),
            'hmac_sha1' => hash_hmac('sha1', $text(1), $text(0)),
            'sha1' => sha1($text(0)),
            'sha256' => hash('sha256', $text(0)),
            'md5' => md5($text(0)),
            'base64' => base64_encode($text(0)),
            'url_encode' => rawurlencode($text(0)),
            'date' => gmdate($text(0) !== '' ? $text(0) : DATE_ATOM, $int(1, time())),
            'upper' => mb_strtoupper($text(0)),
            'lower' => mb_strtolower($text(0)),
            'json' => (string) json_encode($arguments[0] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => '',
        };
    }

    /**
     * How a value is written into text.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, is_scalar(...)) === $value => implode(',', array_map(self::text(...), $value)),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
