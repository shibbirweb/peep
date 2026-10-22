<?php

namespace ShibbirWeb\Peep\Envelope;

use ShibbirWeb\Peep\Payload\PayloadInterface;

/**
 * One request to the viewer: a card id, its payloads and meta data.
 */
final class Envelope
{
    public const PROTOCOL = 1;

    /**
     * @var string
     */
    private $card;

    /**
     * @var list<PayloadInterface>
     */
    private $payloads;

    /**
     * @var array<string, mixed>
     */
    private $meta;

    /**
     * @param list<PayloadInterface> $payloads
     * @param array<string, mixed> $meta
     */
    public function __construct(string $card, array $payloads, array $meta)
    {
        $this->card = $card;
        $this->payloads = $payloads;
        $this->meta = $meta;
    }

    public function card(): string
    {
        return $this->card;
    }

    /**
     * @return list<PayloadInterface>
     */
    public function payloads(): array
    {
        return $this->payloads;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return array{protocol: int, card: string, payloads: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        $payloads = [];
        foreach ($this->payloads as $payload) {
            $payloads[] = $payload->toArray();
        }

        return [
            'protocol' => self::PROTOCOL,
            'card' => $this->card,
            'payloads' => $payloads,
            'meta' => $this->meta,
        ];
    }
}
