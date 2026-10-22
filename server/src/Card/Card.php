<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Card;

use ShibbirWeb\PeepServer\Protocol\Envelope;
use ShibbirWeb\PeepServer\Protocol\Payload;

/**
 * One card in the viewer: the result of one peep() call and every chained call on it.
 */
final class Card
{
    /**
     * Payload types that describe the current state of one thing (a counter, a repeated
     * query, an HTTP call, a job): a newer one replaces the older one on the card.
     */
    public const array LATEST_ONLY_TYPES = ['count', 'query', 'http', 'job'];

    /**
     * @var list<Payload>
     */
    private array $payloads = [];

    public private(set) ?string $color = null;

    public private(set) ?string $label = null;

    public private(set) string $size = 'normal';

    public private(set) bool $hidden = false;

    /**
     * How far to open the card's values: level, keys or all (peep()->expand()).
     *
     * @var array<string, mixed>|null
     */
    public private(set) ?array $expand = null;

    public private(set) float $updatedAt;

    /**
     * @param array{id: string, kind: string, label: string, started_at: float} $request
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly array $request,
        public readonly string $hostname,
        public readonly string $phpVersion,
        public readonly float $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function fromEnvelope(Envelope $envelope, float $now): self
    {
        return new self(
            id: $envelope->card,
            project: $envelope->project,
            request: $envelope->request,
            hostname: $envelope->hostname,
            phpVersion: $envelope->phpVersion,
            createdAt: $now,
        );
    }

    /**
     * Restores a card saved with toArray() (history). Null when the data is not a card.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $request = $data['request'] ?? null;
        if (
            ! is_string($data['id'] ?? null) || ! is_string($data['project'] ?? null)
            || ! is_array($request) || ! is_string($request['id'] ?? null)
            || ! is_float($data['created_at'] ?? null) || ! is_array($data['payloads'] ?? null)
        ) {
            return null;
        }

        $card = new self(
            id: $data['id'],
            project: $data['project'],
            request: [
                'id' => $request['id'],
                'kind' => is_string($request['kind'] ?? null) ? $request['kind'] : 'unknown',
                'label' => is_string($request['label'] ?? null) ? $request['label'] : '',
                'started_at' => is_float($request['started_at'] ?? null) ? $request['started_at'] : $data['created_at'],
            ],
            hostname: is_string($data['hostname'] ?? null) ? $data['hostname'] : '',
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : '',
            createdAt: $data['created_at'],
        );

        foreach ($data['payloads'] as $payload) {
            if (is_array($payload) && is_string($payload['type'] ?? null) && is_array($payload['content'] ?? null)) {
                $origin = $payload['origin'] ?? null;
                $card->payloads[] = new Payload(
                    $payload['type'],
                    self::withStringKeys($payload['content']),
                    is_array($origin) && is_string($origin['file'] ?? null) && is_int($origin['line'] ?? null) ? [
                        'file' => $origin['file'],
                        'line' => $origin['line'],
                        'function' => is_string($origin['function'] ?? null) ? $origin['function'] : null,
                        'class' => is_string($origin['class'] ?? null) ? $origin['class'] : null,
                    ] : null,
                );
            }
        }

        $card->color = is_string($data['color'] ?? null) ? $data['color'] : null;
        $card->label = is_string($data['label'] ?? null) ? $data['label'] : null;
        $card->size = is_string($data['size'] ?? null) ? $data['size'] : 'normal';
        $card->hidden = ($data['hidden'] ?? false) === true;
        $card->expand = is_array($data['expand'] ?? null) ? self::withStringKeys($data['expand']) : null;
        $card->updatedAt = is_float($data['updated_at'] ?? null) ? $data['updated_at'] : $card->createdAt;

        return $card;
    }

    /**
     * Applies one payload. Content payloads are appended; update payloads change the card.
     * A "remove" payload is handled by the store, not here.
     */
    public function apply(Payload $payload, float $now): void
    {
        $this->updatedAt = $now;

        match ($payload->type) {
            'color' => $this->color = self::stringContent($payload, 'color'),
            'label' => $this->label = self::stringContent($payload, 'label'),
            'size' => $this->size = self::stringContent($payload, 'size') ?? 'normal',
            'replace' => $this->payloads = [new Payload('log', ['values' => $payload->content['values'] ?? []], $this->origin())],
            'hide' => $this->hidden = true,
            'expand' => $this->expand = $payload->content,
            default => $this->payloads = in_array($payload->type, self::LATEST_ONLY_TYPES, true)
                ? [...array_values(array_filter($this->payloads, static fn(Payload $existing): bool => $existing->type !== $payload->type)), $payload]
                : [...$this->payloads, $payload],
        };
    }

    /**
     * The origin of the first content payload: where the card was created.
     *
     * @return array{file: string, line: int, function: string|null, class: string|null}|null
     */
    public function origin(): ?array
    {
        return array_find($this->payloads, static fn(Payload $payload): bool => $payload->origin !== null)?->origin;
    }

    /**
     * @return array{id: string, project: string, request: array{id: string, kind: string, label: string, started_at: float}, hostname: string, php_version: string, origin: array{file: string, line: int, function: string|null, class: string|null}|null, color: string|null, label: string|null, size: string, hidden: bool, expand: array<string, mixed>|null, payloads: list<array{type: string, content: array<string, mixed>, origin: array{file: string, line: int, function: string|null, class: string|null}|null}>, created_at: float, updated_at: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project' => $this->project,
            'request' => $this->request,
            'hostname' => $this->hostname,
            'php_version' => $this->phpVersion,
            'origin' => $this->origin(),
            'color' => $this->color,
            'label' => $this->label,
            'size' => $this->size,
            'hidden' => $this->hidden,
            'expand' => $this->expand,
            'payloads' => array_map(static fn(Payload $payload): array => $payload->toArray(), $this->payloads),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function withStringKeys(array $values): array
    {
        return array_filter($values, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private static function stringContent(Payload $payload, string $key): ?string
    {
        $value = $payload->content[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
