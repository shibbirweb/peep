<?php

namespace ShibbirWeb\Peep\Payload;

/**
 * Sets the label of an existing card.
 */
final class LabelPayload extends AbstractPayload
{
    /**
     * @var string
     */
    private $label;

    public function __construct(string $label)
    {
        parent::__construct(null);

        $this->label = $label;
    }

    public function type(): string
    {
        return 'label';
    }

    public function content(): array
    {
        return ['label' => $this->label];
    }
}
