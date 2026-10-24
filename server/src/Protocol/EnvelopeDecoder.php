<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Protocol;

use JsonException;

/**
 * Decodes and validates a protocol v1 envelope (see docs/protocol.md).
 *
 * Only the fields the viewer relies on are checked; unknown fields are ignored,
 * as the protocol requires.
 */
final class EnvelopeDecoder
{
    public const int PROTOCOL = 1;

    /**
     * @throws InvalidEnvelopeException
     */
    public function decode(string $json): Envelope
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEnvelopeException('invalid_json', 'The body is not valid JSON: ' . $exception->getMessage());
        }

        if (! is_array($data)) {
            throw new InvalidEnvelopeException('invalid_envelope', 'The envelope must be a JSON object.');
        }

        if (($data['protocol'] ?? null) !== self::PROTOCOL) {
            throw new InvalidEnvelopeException('unsupported_protocol', 'Only protocol version 1 is supported.');
        }

        $card = self::nonEmptyString($data, 'card');
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : throw new InvalidEnvelopeException('invalid_envelope', '"meta" must be an object.');
        $request = is_array($meta['request'] ?? null) ? $meta['request'] : throw new InvalidEnvelopeException('invalid_envelope', '"meta.request" must be an object.');

        return new Envelope(
            card: $card,
            payloads: $this->payloads($data['payloads'] ?? null),
            project: self::nonEmptyString($meta, 'project'),
            hostname: self::optionalString($meta, 'hostname'),
            phpVersion: self::optionalString($meta, 'php_version'),
            peepVersion: self::optionalString($meta, 'peep_version'),
            request: [
                'id' => self::nonEmptyString($request, 'id', 'meta.request.id'),
                'kind' => self::optionalString($request, 'kind', 'cli'),
                'label' => self::optionalString($request, 'label'),
                'started_at' => self::optionalFloat($request, 'started_at'),
            ],
            sentAt: self::optionalFloat($meta, 'sent_at'),
            logs: self::logs($meta['logs'] ?? null),
        );
    }

    /**
     * meta.logs: {"path": absolute folder, "timezone": "Europe/Berlin"}. Anything else is ignored,
     * so a client that gets it wrong still delivers its cards.
     *
     * @return array{path: string, timezone: string}|null
     */
    private static function logs(mixed $logs): ?array
    {
        if (! is_array($logs) || ! is_string($logs['path'] ?? null) || strlen($logs['path']) > 4096) {
            return null;
        }

        $path = $logs['path'];
        if (! str_starts_with($path, '/') && preg_match('#^[A-Za-z]:[\\\\/]#', $path) !== 1) {
            return null;
        }

        $timezone = is_string($logs['timezone'] ?? null) ? $logs['timezone'] : 'UTC';
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            $timezone = 'UTC';
        }

        return ['path' => $path, 'timezone' => $timezone];
    }

    /**
     * @return non-empty-list<Payload>
     */
    private function payloads(mixed $payloads): array
    {
        if (! is_array($payloads) || ! array_is_list($payloads) || $payloads === []) {
            throw new InvalidEnvelopeException('invalid_envelope', '"payloads" must be a non-empty array.');
        }

        $decoded = [];
        foreach ($payloads as $index => $payload) {
            if (! is_array($payload)) {
                throw new InvalidEnvelopeException('invalid_payload', "Payload {$index} must be an object.");
            }

            $content = $payload['content'] ?? null;
            if (! is_array($content)) {
                throw new InvalidEnvelopeException('invalid_payload', "Payload {$index} needs a \"content\" object.");
            }

            $decoded[] = new Payload(
                type: self::nonEmptyString($payload, 'type', "payloads.{$index}.type"),
                content: self::withStringKeys($content),
                origin: self::origin($payload['origin'] ?? null),
            );
        }

        return $decoded;
    }

    /**
     * JSON object keys that look like numbers ("0") become integers in PHP arrays.
     *
     * @param array<mixed> $content
     * @return array<string, mixed>
     */
    private static function withStringKeys(array $content): array
    {
        $result = [];
        foreach ($content as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @return array{file: string, line: int, function: string|null, class: string|null}|null
     */
    private static function origin(mixed $origin): ?array
    {
        if (! is_array($origin) || ! is_string($origin['file'] ?? null) || ! is_int($origin['line'] ?? null)) {
            return null;
        }

        return [
            'file' => $origin['file'],
            'line' => $origin['line'],
            'function' => is_string($origin['function'] ?? null) ? $origin['function'] : null,
            'class' => is_string($origin['class'] ?? null) ? $origin['class'] : null,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private static function nonEmptyString(array $data, string $key, ?string $name = null): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidEnvelopeException('invalid_envelope', sprintf('"%s" must be a non-empty string.', $name ?? $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(array $data, string $key, string $default = ''): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : $default;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalFloat(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : microtime(true);
    }
}
