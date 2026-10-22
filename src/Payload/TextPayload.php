<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * Plain text, shown with whitespace kept and never rendered as HTML.
 */
final class TextPayload extends AbstractPayload
{
    /**
     * @var string
     */
    private $text;

    public function __construct(string $text, ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->text = $text;
    }

    public function type(): string
    {
        return 'text';
    }

    public function content(): array
    {
        return ['text' => $this->text];
    }
}
