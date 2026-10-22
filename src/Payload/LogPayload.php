<?php

namespace ShibbirWeb\Peep\Payload;

use ShibbirWeb\Peep\Origin\Origin;

/**
 * One or more values, already serialized to value tree nodes.
 */
final class LogPayload extends AbstractPayload
{
    /**
     * @var list<array<string, mixed>>
     */
    private $values;

    /**
     * @param list<array<string, mixed>> $values
     */
    public function __construct(array $values, ?Origin $origin = null)
    {
        parent::__construct($origin);

        $this->values = $values;
    }

    public function type(): string
    {
        return 'log';
    }

    public function content(): array
    {
        return ['values' => $this->values];
    }
}
