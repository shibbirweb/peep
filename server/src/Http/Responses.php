<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http;

use Amp\Http\Server\Response;

/**
 * Small factory for the JSON responses every controller returns.
 */
final class Responses
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     */
    public static function json(int $status, array $data): Response
    {
        return new Response(
            $status,
            [
                'content-type' => 'application/json',
                'cache-control' => 'no-store',
            ],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        );
    }

    public static function error(int $status, string $error, string $message): Response
    {
        return self::json($status, ['error' => $error, 'message' => $message]);
    }
}
