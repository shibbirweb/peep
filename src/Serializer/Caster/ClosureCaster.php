<?php

namespace ShibbirWeb\Peep\Serializer\Caster;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionType;
use Throwable;

/**
 * Closures: where they are defined and which parameters they take.
 */
final class ClosureCaster implements CasterInterface
{
    public function supports($object): bool
    {
        return $object instanceof Closure;
    }

    public function cast($object): array
    {
        if (! $object instanceof Closure) {
            return ['summary' => null, 'properties' => []];
        }

        try {
            $reflection = new ReflectionFunction($object);
        } catch (Throwable $exception) {
            return ['summary' => null, 'properties' => []];
        }

        $parameters = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameters[] = ($type !== null ? self::typeName($type) . ' ' : '') . '$' . $parameter->getName();
        }

        $signature = 'function (' . implode(', ', $parameters) . ')';
        $file = $reflection->getFileName();

        $properties = [['signature', 'public', $signature]];
        if (is_string($file)) {
            $properties[] = ['file', 'public', $file . ':' . $reflection->getStartLine()];
        }

        return ['summary' => $signature, 'properties' => $properties];
    }

    /**
     * ReflectionType::__toString() is deprecated on PHP 7.1 to 7.4, so named types use getName().
     */
    private static function typeName(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $nullable = $type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true);

            return ($nullable ? '?' : '') . $type->getName();
        }

        // Union and intersection types only exist on PHP 8.0+, where __toString() is fine.
        return PHP_VERSION_ID >= 80000 ? (string) $type : '';
    }
}
