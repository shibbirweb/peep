<?php

namespace ShibbirWeb\Peep\Transport;

final class TransportResponse
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var string
     */
    private $body;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
