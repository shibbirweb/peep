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
     * @var list<Payload>
     */
    private array $payloads = [];

    public private(set) ?string $color = null;

    public private(set) ?string $label = null;

    public private(set) string $size = 'normal';

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
            default => $this->payloads[] = $payload,
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
     * @return array{id: string, project: string, request: array{id: string, kind: string, label: string, started_at: float}, hostname: string, php_version: string, origin: array{file: string, line: int, function: string|null, class: string|null}|null, color: string|null, label: string|null, size: string, payloads: list<array{type: string, content: array<string, mixed>, origin: array{file: string, line: int, function: string|null, class: string|null}|null}>, created_at: float, updated_at: float}
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
            'payloads' => array_map(static fn(Payload $payload): array => $payload->toArray(), $this->payloads),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function stringContent(Payload $payload, string $key): ?string
    {
        $value = $payload->content[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
