<?php

namespace ShibbirWeb\Peep\Serializer\Caster;

/**
 * Shows a kind of object in a more useful way than its raw properties.
 */
interface CasterInterface
{
    /**
     * @param object $object
     */
    public function supports($object): bool;

    /**
     * @param object $object
     * @return array{summary: string|null, properties: list<array{0: string, 1: string, 2: mixed}>}
     *         A one-line summary, and the properties to show as [name, visibility, value].
     */
    public function cast($object): array;
}
