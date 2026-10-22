<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * One payload of an envelope (see docs/protocol.md, "Payload types").
 */
interface PayloadInterface
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function content(): array;

    public function origin(): ?Origin;

    /**
     * @return array{type: string, content: array<string, mixed>, origin: array<string, mixed>|null}
     */
    public function toArray(): array;
}
