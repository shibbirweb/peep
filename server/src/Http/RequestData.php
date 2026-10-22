<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\FormParser\FormParser;
use Amp\Http\Server\Request;

/**
 * Reads what provider-style APIs send: JSON, form-urlencoded or multipart.
 */
final class RequestData
{
    public const int MAX_BYTES = 30 * 1024 * 1024;

    private function __construct() {}

    /**
     * @return array<string, mixed>|null Null when the body is too large or not readable.
     */
    public static function fields(Request $request): ?array
    {
        $form = self::form($request);
        if ($form !== null) {
            $fields = [];
            foreach ($form->getNames() as $name) {
                $values = $form->getValueArray($name);
                $fields[$name] = count($values) === 1 ? $values[0] : $values;
            }

            return $fields;
        }

        $body = self::body($request);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    public static function form(Request $request): ?Form
    {
        $type = strtolower((string) $request->getHeader('content-type'));

        if (! str_starts_with($type, 'multipart/form-data') && ! str_starts_with($type, 'application/x-www-form-urlencoded')) {
            return null;
        }

        $request->getBody()->increaseSizeLimit(self::MAX_BYTES);

        try {
            return new FormParser(fieldCountLimit: 10000)->parseForm($request);
        } catch (BufferException | ClientException) {
            return null;
        }
    }

    public static function body(Request $request): ?string
    {
        $request->getBody()->increaseSizeLimit(self::MAX_BYTES);

        try {
            return $request->getBody()->buffer(limit: self::MAX_BYTES);
        } catch (BufferException | ClientException) {
            return null;
        }
    }

    /**
     * The project from the X-Peep-Project header or a "project" field, else "default".
     *
     * @param array<string, mixed> $fields
     */
    public static function project(Request $request, array $fields = []): string
    {
        $header = trim((string) $request->getHeader('x-peep-project'));
        if ($header !== '') {
            return $header;
        }

        return is_string($fields['project'] ?? null) && trim($fields['project']) !== '' ? trim($fields['project']) : 'default';
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function string(array $fields, string ...$names): ?string
    {
        foreach ($names as $name) {
            $value = $fields[$name] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * A field that may be repeated or comma separated (Mailgun "to").
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public static function list(array $fields, string $name): array
    {
        $values = $fields[$name] ?? [];
        $values = is_array($values) ? $values : [$values];

        $list = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                foreach (str_getcsv($value, ',', '"', '') as $address) {
                    if (is_string($address) && trim($address) !== '') {
                        $list[] = trim($address);
                    }
                }
            }
        }

        return $list;
    }
}
