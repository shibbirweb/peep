<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * An array value shown as a table.
 */
final class TablePayload extends AbstractPayload
{
    /**
     * @var array<string, mixed>
     */
    private $rows;

    /**
     * @var string|null
     */
    private $label;

    /**
     * @param array<string, mixed> $rows The array, already serialized to a value tree node.
     */
    public function __construct(array $rows, ?string $label = null, ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->rows = $rows;
        $this->label = $label;
    }

    public function type(): string
    {
        return 'table';
    }

    public function content(): array
    {
        return ['rows' => $this->rows, 'label' => $this->label];
    }
}
