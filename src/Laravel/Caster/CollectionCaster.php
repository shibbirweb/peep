<?php

namespace ShibbirWeb\Peep\Laravel\Caster;

use ShibbirWeb\Peep\Serializer\Caster\CasterInterface;

/**
 * Collections show their items directly. Lazy collections are left alone: reading them
 * would run their generator.
 */
final class CollectionCaster implements CasterInterface
{
    private const COLLECTION = 'Illuminate\\Support\\Collection';

    public function supports($object): bool
    {
        return is_a($object, self::COLLECTION);
    }

    public function cast($object): array
    {
        $items = method_exists($object, 'all') ? $object->all() : [];

        return [
            'summary' => count($items) . ' ' . (count($items) === 1 ? 'item' : 'items'),
            'properties' => [['items', 'protected', $items]],
        ];
    }
}
