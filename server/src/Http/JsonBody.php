<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;

/**
 * Reads the small JSON objects the UI sends with its actions.
 */
final class JsonBody
{
    public const int LIMIT = 65536;

    private function __construct() {}

    /**
     * @return array<mixed> An empty array when the body is not a JSON object.
     */
    public static function read(Request $request): array
    {
        try {
            $data = json_decode($request->getBody()->buffer(limit: self::LIMIT), true);
        } catch (BufferException | ClientException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<mixed> $data
     */
    public static function project(array $data): ?string
    {
        return is_string($data['project'] ?? null) && $data['project'] !== '' ? $data['project'] : null;
    }
}
