<?php

namespace ShibbirWeb\Peep\Serializer\Caster;

use Throwable;

/**
 * Exceptions and errors, without the full trace (use peep()->exception() for that).
 */
final class ThrowableCaster implements CasterInterface
{
    public function supports($object): bool
    {
        return $object instanceof Throwable;
    }

    public function cast($object): array
    {
        if (! $object instanceof Throwable) {
            return ['summary' => null, 'properties' => []];
        }

        $properties = [
            ['message', 'protected', $object->getMessage()],
            ['code', 'protected', $object->getCode()],
            ['file', 'protected', $object->getFile() . ':' . $object->getLine()],
        ];

        if ($object->getPrevious() !== null) {
            $properties[] = ['previous', 'private', $object->getPrevious()];
        }

        return [
            'summary' => $object->getMessage(),
            'properties' => $properties,
        ];
    }
}
