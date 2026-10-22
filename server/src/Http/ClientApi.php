<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http;

/**
 * The endpoints apps call. Everything else (UI, assets, stream, UI actions) is for the
 * viewer's own page and is only served to this machine.
 */
final class ClientApi
{
    private const array PATHS = ['/_peep/envelope', '/_peep/switches', '/_peep/health'];

    private const string LOCK_POLL_PREFIX = '/_peep/locks/';

    private function __construct() {}

    public static function isClientPath(string $path): bool
    {
        return in_array($path, self::PATHS, true) || str_starts_with($path, self::LOCK_POLL_PREFIX);
    }
}
