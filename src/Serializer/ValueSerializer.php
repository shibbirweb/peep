<?php

namespace ShibbirWeb\Peep\Serializer;

use ReflectionClass;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Serializer\Caster\CasterInterface;
use ShibbirWeb\Peep\Serializer\Caster\ClosureCaster;
use ShibbirWeb\Peep\Serializer\Caster\DateTimeCaster;
use ShibbirWeb\Peep\Serializer\Caster\EnumCaster;
use ShibbirWeb\Peep\Serializer\Caster\ThrowableCaster;
use SplObjectStorage;
use Throwable;

/**
 * Default serializer: builds the protocol's value tree with depth, size and node limits,
 * loop detection and redaction of sensitive keys.
 *
 * Object properties are read with an (array) cast. It returns public, protected and
 * private properties on every PHP version from 7.1 to 8.5, without Reflection, without
 * setAccessible() and without calling getters, so reading a value never changes it.
 */
final class ValueSerializer implements SerializerInterface
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @var int
     */
    private $maxDepth;

    /**
     * @var int
     */
    private $maxItems;

    /**
     * @var int
     */
    private $maxStringLength;

    /**
     * @var int
     */
    private $maxNodes;

    /**
     * @var array<string, true>
     */
    private $redactKeys;

    /**
     * @var list<CasterInterface>
     */
    private $casters;

    /**
     * @var int
     */
    private $nodeCount = 0;

    /**
     * Object => id within the current value. SplObjectStorage holds a reference to each
     * object, so an object freed during serialization cannot be confused with a new one.
     * It is used through ArrayAccess only (attach() and contains() are deprecated in 8.5).
     *
     * @var SplObjectStorage<object, int>|null
     */
    private $objectIds;

    /**
     * @param list<string> $redactKeys
     * @param list<CasterInterface>|null $casters Null uses the default casters.
     */
    public function __construct(int $maxDepth, int $maxItems, int $maxStringLength, int $maxNodes, array $redactKeys, ?array $casters = null)
    {
        $this->casters = $casters !== null ? $casters : self::defaultCasters();
        $this->maxDepth = $maxDepth;
        $this->maxItems = $maxItems;
        $this->maxStringLength = $maxStringLength;
        $this->maxNodes = $maxNodes;

        $this->redactKeys = [];
        foreach ($redactKeys as $key) {
            $this->redactKeys[strtolower($key)] = true;
        }
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->maxDepth(),
            $config->maxItems(),
            $config->maxStringLength(),
            $config->maxNodes(),
            $config->redactKeys()
        );
    }

    /**
     * @return list<CasterInterface>
     */
    public static function defaultCasters(): array
    {
        return [new DateTimeCaster(), new ThrowableCaster(), new ClosureCaster(), new EnumCaster()];
    }

    /**
     * A copy that shows every object with its raw properties (peep()->raw()).
     */
    public function withoutCasters(): self
    {
        $copy = clone $this;
        $copy->casters = [];

        return $copy;
    }

    /**
     * A copy with an extra caster tried first (framework adapters, e.g. Eloquent models).
     */
    public function withCaster(CasterInterface $caster): self
    {
        $copy = clone $this;
        $copy->casters = array_merge([$caster], $this->casters);

        return $copy;
    }

    public function serialize($value): array
    {
        $this->nodeCount = 0;
        $this->objectIds = new SplObjectStorage();

        try {
            return $this->node($value, 0);
        } finally {
            $this->objectIds = null;
        }
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function node($value, int $depth): array
    {
        $this->nodeCount++;

        if ($value === null) {
            return ['type' => 'null'];
        }

        if (is_bool($value)) {
            return ['type' => 'bool', 'value' => $value];
        }

        if (is_int($value)) {
            return $this->intNode($value);
        }

        if (is_float($value)) {
            return $this->floatNode($value);
        }

        if (is_string($value)) {
            return $this->stringNode($value);
        }

        if (is_array($value)) {
            return $this->arrayNode($value, $depth);
        }

        if (is_object($value)) {
            return $this->objectNode($value, $depth);
        }

        return $this->resourceNode($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function intNode(int $value): array
    {
        // JavaScript loses precision beyond 2^53, so larger integers travel as strings.
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            return ['type' => 'int', 'value' => (string) $value];
        }

        return ['type' => 'int', 'value' => $value];
    }

    /**
     * @return array<string, mixed>
     */
    private function floatNode(float $value): array
    {
        if (is_nan($value)) {
            return ['type' => 'float', 'value' => 'NAN'];
        }

        if (is_infinite($value)) {
            return ['type' => 'float', 'value' => $value > 0 ? 'INF' : '-INF'];
        }

        return ['type' => 'float', 'value' => $value];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringNode(string $value): array
    {
        if (preg_match('//u', $value) !== 1) {
            $length = strlen($value);
            $node = [
                'type' => 'binary',
                'value' => base64_encode(substr($value, 0, $this->maxStringLength)),
                'length' => $length,
            ];

            if ($length > $this->maxStringLength) {
                $node['truncated'] = $length - $this->maxStringLength;
            }

            return $node;
        }

        $length = self::characterLength($value);
        $node = ['type' => 'string', 'value' => $value, 'length' => $length];

        if ($length > $this->maxStringLength) {
            $node['value'] = self::characterSubstring($value, $this->maxStringLength);
            $node['truncated'] = $length - $this->maxStringLength;
        }

        return $node;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function arrayNode(array $value, int $depth): array
    {
        $length = count($value);

        if ($depth >= $this->maxDepth) {
            return ['type' => 'max_depth', 'length' => $length];
        }

        $children = [];
        $added = 0;

        foreach ($value as $key => $item) {
            if ($added >= $this->maxItems || $this->nodeCount >= $this->maxNodes) {
                break;
            }

            $children[] = ['key' => $key, 'node' => $this->childNode($key, $item, $depth)];
            $added++;
        }

        $node = ['type' => 'array', 'length' => $length, 'children' => $children];

        if ($added < $length) {
            $node['truncated'] = $length - $added;
        }

        return $node;
    }

    /**
     * @param object $value
     * @return array<string, mixed>
     */
    private function objectNode($value, int $depth): array
    {
        $objectIds = $this->objectIds;
        if ($objectIds === null) {
            $objectIds = $this->objectIds = new SplObjectStorage();
        }

        if (isset($objectIds[$value])) {
            return ['type' => 'ref', 'id' => $objectIds[$value]];
        }

        $class = get_class($value);

        if ($depth >= $this->maxDepth) {
            return ['type' => 'max_depth', 'class' => $class];
        }

        $id = count($objectIds) + 1;
        $objectIds[$value] = $id;

        if (self::isUninitializedLazyObject($value)) {
            return ['type' => 'object', 'class' => $class, 'id' => $id, 'length' => 0, 'children' => [], 'lazy' => true];
        }

        $summary = null;
        $caster = $this->casterFor($value);

        if ($caster !== null) {
            $cast = $caster->cast($value);
            $summary = $cast['summary'];
            $properties = $cast['properties'];
        } else {
            $properties = self::properties($value);
        }

        $length = count($properties);
        $children = [];
        $added = 0;

        foreach ($properties as $property) {
            if ($added >= $this->maxItems || $this->nodeCount >= $this->maxNodes) {
                break;
            }

            $children[] = [
                'key' => $property[0],
                'visibility' => $property[1],
                'node' => $this->childNode($property[0], $property[2], $depth),
            ];
            $added++;
        }

        $node = ['type' => 'object', 'class' => $class, 'id' => $id, 'length' => $length, 'children' => $children];

        if ($summary !== null) {
            $node['summary'] = $summary;
        }

        if ($added < $length) {
            $node['truncated'] = $length - $added;
        }

        return $node;
    }

    /**
     * @param object $value
     */
    private function casterFor($value): ?CasterInterface
    {
        foreach ($this->casters as $caster) {
            try {
                if ($caster->supports($value)) {
                    return $caster;
                }
            } catch (Throwable $exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function resourceNode($value): array
    {
        if (is_resource($value)) {
            return ['type' => 'resource', 'value' => get_resource_type($value)];
        }

        // A closed resource is no longer is_resource(); gettype() reports "resource (closed)".
        return ['type' => 'resource', 'value' => 'closed'];
    }

    /**
     * @param int|string $key
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function childNode($key, $value, int $depth): array
    {
        if (is_string($key) && isset($this->redactKeys[strtolower($key)])) {
            $this->nodeCount++;

            return ['type' => 'string', 'value' => '***', 'redacted' => true];
        }

        return $this->node($value, $depth + 1);
    }

    /**
     * Properties as [name, visibility, value]. Uses __debugInfo() when defined, like var_dump().
     *
     * @param object $object
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private static function properties($object): array
    {
        $values = null;

        if (method_exists($object, '__debugInfo')) {
            try {
                $debugInfo = $object->__debugInfo();
                if (is_array($debugInfo)) {
                    $values = $debugInfo;
                }
            } catch (Throwable $exception) {
                $values = null;
            }
        }

        if ($values === null) {
            $values = (array) $object;
        }

        $properties = [];

        foreach ($values as $rawKey => $value) {
            $name = (string) $rawKey;
            $visibility = 'public';

            // Private and protected keys are mangled: "\0Class\0name" and "\0*\0name".
            if (isset($name[0]) && $name[0] === "\0") {
                $parts = explode("\0", $name, 3);
                if (count($parts) === 3) {
                    $visibility = $parts[1] === '*' ? 'protected' : 'private';
                    $name = $parts[2];
                }
            }

            $properties[] = [$name, $visibility, $value];
        }

        return $properties;
    }

    /**
     * PHP 8.4 lazy objects must not be initialized just to be shown.
     *
     * @param object $object
     */
    private static function isUninitializedLazyObject($object): bool
    {
        if (PHP_VERSION_ID < 80400) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($object);

            return $reflection->isUninitializedLazyObject($object);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        if (function_exists('iconv_strlen')) {
            $length = iconv_strlen($value, 'UTF-8');
            if (is_int($length)) {
                return $length;
            }
        }

        $count = preg_match_all('/./us', $value);

        return is_int($count) ? $count : strlen($value);
    }

    private static function characterSubstring(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        if (function_exists('iconv_substr')) {
            $substring = iconv_substr($value, 0, $length, 'UTF-8');
            if (is_string($substring)) {
                return $substring;
            }
        }

        // PCRE quantifiers stop at 65535.
        if ($length <= 65535 && preg_match('/^.{0,' . $length . '}/us', $value, $matches) === 1) {
            return $matches[0];
        }

        return substr($value, 0, $length);
    }
}
