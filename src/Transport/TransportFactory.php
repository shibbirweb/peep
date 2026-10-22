<?php

namespace ShibbirWeb\Peep\Transport;

use ShibbirWeb\Peep\Config\Config;

/**
 * Picks the best available transport: sockets, then curl, then nothing.
 */
final class TransportFactory
{
    private function __construct()
    {
    }

    public static function create(Config $config): TransportInterface
    {
        if (self::isAvailable('stream_socket_client')) {
            return new StreamTransport($config->host(), $config->port(), $config->connectTimeoutMs(), $config->timeoutMs(), $config->token());
        }

        if (self::isAvailable('curl_init')) {
            return new CurlTransport($config->host(), $config->port(), $config->connectTimeoutMs(), $config->timeoutMs(), $config->token());
        }

        return new NullTransport();
    }

    private static function isAvailable(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if (! is_string($disabled) || $disabled === '') {
            return true;
        }

        $names = array_map('trim', explode(',', strtolower($disabled)));

        return ! in_array($function, $names, true);
    }
}
