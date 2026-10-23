<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Protocol;

/**
 * A decoded, validated protocol v1 envelope.
 */
final readonly class Envelope
{
    /**
     * @param non-empty-list<Payload> $payloads
     * @param array{id: string, kind: string, label: string, started_at: float} $request
     * @param array{path: string, timezone: string}|null $logs The app's log folder on this machine (meta.logs).
     */
    public function __construct(
        public string $card,
        public array $payloads,
        public string $project,
        public string $hostname,
        public string $phpVersion,
        public string $peepVersion,
        public array $request,
        public float $sentAt,
        public ?array $logs = null,
    ) {}
}
