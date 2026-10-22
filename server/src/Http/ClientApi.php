<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http;

/**
 * The endpoints apps call. Everything else (UI, assets, stream, UI actions) is for the
 * viewer's own page and is only served to this machine.
 */
final class ClientApi
{
    private const array PATHS = ['/_peep/envelope', '/_peep/switches', '/_peep/health', '/_peep/mail', '/_peep/sms', '/sms/json'];

    /**
     * Lock polling, and the Mailgun and Twilio compatible APIs.
     */
    private const array PREFIXES = ['/_peep/locks/', '/v3/', '/2010-04-01/'];

    private function __construct() {}

    public static function isClientPath(string $path): bool
    {
        if (in_array($path, self::PATHS, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
