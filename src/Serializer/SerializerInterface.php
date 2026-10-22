<?php

namespace ShibbirWeb\Peep\Serializer;

/**
 * Turns any PHP value into a value tree node (see docs/protocol.md, "Value tree").
 * Implementations must never throw and never cause side effects in the value.
 */
interface SerializerInterface
{
    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public function serialize($value): array;
}
