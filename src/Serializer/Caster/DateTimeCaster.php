<?php

namespace ShibbirWeb\Peep\Serializer\Caster;

use DateTimeInterface;

/**
 * DateTime, DateTimeImmutable and subclasses such as Carbon.
 */
final class DateTimeCaster implements CasterInterface
{
    public function supports($object): bool
    {
        return $object instanceof DateTimeInterface;
    }

    public function cast($object): array
    {
        if (! $object instanceof DateTimeInterface) {
            return ['summary' => null, 'properties' => []];
        }

        return [
            'summary' => $object->format('Y-m-d H:i:s.u P'),
            'properties' => [
                ['date', 'public', $object->format('Y-m-d H:i:s.u')],
                ['timezone', 'public', $object->getTimezone()->getName()],
                ['timestamp', 'public', $object->getTimestamp()],
            ],
        ];
    }
}
