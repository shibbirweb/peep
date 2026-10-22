<?php

namespace ShibbirWeb\Peep\Serializer\Caster;

/**
 * PHP 8.1+ enum cases. On older PHP no object is an enum, so this caster never applies.
 */
final class EnumCaster implements CasterInterface
{
    public function supports($object): bool
    {
        return $object instanceof \UnitEnum;
    }

    public function cast($object): array
    {
        $vars = get_object_vars($object);
        $name = isset($vars['name']) && is_string($vars['name']) ? $vars['name'] : '';
        $summary = get_class($object) . '::' . $name;
        $properties = [['name', 'public', $name]];

        if (array_key_exists('value', $vars)) {
            $properties[] = ['value', 'public', $vars['value']];
        }

        return ['summary' => $summary, 'properties' => $properties];
    }
}
