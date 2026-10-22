<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * A JSON string. The viewer pretty-prints it, or shows it as text when it is invalid.
 */
final class JsonPayload extends AbstractPayload
{
    /**
     * @var string
     */
    private $json;

    public function __construct(string $json, ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->json = $json;
    }

    public function type(): string
    {
        return 'json';
    }

    public function content(): array
    {
        return ['json' => $this->json];
    }
}
