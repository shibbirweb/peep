<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Sms;

/**
 * One caught SMS.
 */
final class Sms
{
    public bool $read = false;

    /**
     * @param array{encoding: string, length: int, segments: int, per_segment: int} $segments
     * @param list<string> $media
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sid,
        public readonly string $project,
        public readonly ?string $requestId,
        public readonly string $to,
        public readonly string $from,
        public readonly string $body,
        public readonly array $segments,
        public readonly array $media,
        public readonly string $source,
        public readonly ?string $statusCallback,
        public readonly float $receivedAt,
    ) {}

    /**
     * @param list<string> $media
     */
    public static function create(
        string $project,
        string $to,
        string $from,
        string $body,
        string $source,
        float $now,
        ?string $requestId = null,
        ?string $statusCallback = null,
        array $media = [],
    ): self {
        return new self(
            id: 'sms_' . bin2hex(random_bytes(8)),
            // Twilio message SIDs: "SM" and 32 hex characters.
            sid: 'SM' . bin2hex(random_bytes(16)),
            project: $project,
            requestId: $requestId,
            to: $to,
            from: $from,
            body: $body,
            segments: SmsSegments::of($body),
            media: $media,
            source: $source,
            statusCallback: $statusCallback,
            receivedAt: $now,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sid' => $this->sid,
            'project' => $this->project,
            'request_id' => $this->requestId,
            'to' => $this->to,
            'from' => $this->from,
            'body' => $this->body,
            'segments' => $this->segments,
            'media' => $this->media,
            'source' => $this->source,
            'status_callback' => $this->statusCallback,
            'received_at' => $this->receivedAt,
            'read' => $this->read,
        ];
    }
}
