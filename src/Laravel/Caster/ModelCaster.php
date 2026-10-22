<?php

namespace ShibbirWeb\Peep\Laravel\Caster;

use ShibbirWeb\Peep\Serializer\Caster\CasterInterface;

/**
 * Eloquent models: attributes and loaded relations, read without accessors and without
 * lazy loading, so showing a model never runs a query.
 */
final class ModelCaster implements CasterInterface
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    public function supports($object): bool
    {
        return is_a($object, self::MODEL);
    }

    public function cast($object): array
    {
        $key = method_exists($object, 'getKey') ? $object->getKey() : null;
        $properties = [
            ['attributes', 'protected', method_exists($object, 'getAttributes') ? $object->getAttributes() : []],
            ['relations', 'protected', method_exists($object, 'getRelations') ? $object->getRelations() : []],
            ['exists', 'public', isset($object->exists) ? (bool) $object->exists : false],
        ];

        $dirty = method_exists($object, 'getDirty') ? $object->getDirty() : [];
        if ($dirty !== []) {
            $properties[] = ['changes', 'protected', $dirty];
        }

        return [
            'summary' => is_scalar($key) ? 'key ' . (string) $key : 'not saved',
            'properties' => $properties,
        ];
    }
}
