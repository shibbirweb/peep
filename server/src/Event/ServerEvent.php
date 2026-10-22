<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Event;

/**
 * An event pushed to the UI over Server-Sent Events.
 */
final readonly class ServerEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $name,
        public array $data,
        public int $id = 0,
    ) {}

    public function withId(int $id): self
    {
        return clone($this, ['id' => $id]);
    }

    /**
     * The event in SSE wire format.
     */
    public function toSse(): string
    {
        return 'id: ' . $this->id . "\n"
            . 'event: ' . $this->name . "\n"
            . 'data: ' . json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n\n";
    }
}
