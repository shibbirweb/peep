<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * HTML, shown by the viewer in a sandboxed frame.
 */
final class HtmlPayload extends AbstractPayload
{
    /**
     * @var string
     */
    private $html;

    public function __construct(string $html, ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->html = $html;
    }

    public function type(): string
    {
        return 'html';
    }

    public function content(): array
    {
        return ['html' => $this->html];
    }
}
