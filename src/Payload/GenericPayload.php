<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * A payload whose content needs no preparation: separator, xml, link, notify,
 * screen and app commands, updates such as hide or remove, and custom types.
 */
final class GenericPayload extends AbstractPayload
{
    /**
     * @var string
     */
    private $type;

    /**
     * @var array<string, mixed>
     */
    private $content;

    /**
     * @param array<string, mixed> $content
     */
    public function __construct(string $type, array $content = [], ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->type = $type;
        $this->content = $content;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function content(): array
    {
        return $this->content;
    }
}
