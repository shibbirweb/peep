<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

abstract class AbstractPayload implements PayloadInterface
{
    /**
     * @var Origin|null
     */
    private $origin;

    public function __construct(?Origin $origin = null)
    {
        $this->origin = $origin;
    }

    public function origin(): ?Origin
    {
        return $this->origin;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'content' => $this->content(),
            'origin' => $this->origin !== null ? $this->origin->toArray() : null,
        ];
    }
}
