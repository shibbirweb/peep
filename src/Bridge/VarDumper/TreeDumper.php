<?php

namespace ShibbirWeb\Peep\Bridge\VarDumper;

use Symfony\Component\VarDumper\Cloner\Cursor;
use Symfony\Component\VarDumper\Cloner\DumperInterface;

/**
 * Turns Symfony VarDumper's cloned Data into Peep's value tree (docs/protocol.md).
 *
 * Works with VarDumper 3.4 to 8: parameters are untyped (newer versions type them, and
 * leaving them untyped is allowed) and every method returns void.
 */
final class TreeDumper implements DumperInterface
{
    /**
     * @var list<array{node: array<string, mixed>, key: string|int|null, visibility: string|null}>
     */
    private $stack = [];

    /**
     * @var array<string, mixed>|null
     */
    private $root;

    /**
     * @var array<int, int> VarDumper object handle => Peep object id
     */
    private $objectIds = [];

    /**
     * @return array<string, mixed>
     */
    public function tree(): array
    {
        return $this->root !== null ? $this->root : ['type' => 'null'];
    }

    /**
     * @param string $type
     * @param string|int|float|bool|null $value
     */
    public function dumpScalar(Cursor $cursor, $type, $value): void
    {
        switch ($type) {
            case 'NULL':
                $node = ['type' => 'null'];
                break;
            case 'boolean':
                $node = ['type' => 'bool', 'value' => (bool) $value];
                break;
            case 'integer':
                $node = ['type' => 'int', 'value' => $value];
                break;
            case 'double':
                $node = is_float($value) && (is_nan($value) || is_infinite($value))
                    ? ['type' => 'float', 'value' => is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')]
                    : ['type' => 'float', 'value' => $value];
                break;
            default:
                // "label" and other caster values: show as text.
                $node = ['type' => 'string', 'value' => (string) $value, 'length' => strlen((string) $value)];
        }

        $this->add($cursor, $node);
    }

    /**
     * @param string $str
     * @param bool $bin
     * @param int $cut
     */
    public function dumpString(Cursor $cursor, $str, $bin, $cut): void
    {
        $str = (string) $str;

        if ($bin) {
            $node = ['type' => 'binary', 'value' => base64_encode($str), 'length' => strlen($str) + (int) $cut];
        } else {
            $length = function_exists('mb_strlen') ? mb_strlen($str, 'UTF-8') : strlen($str);
            $node = ['type' => 'string', 'value' => $str, 'length' => $length + (int) $cut];
        }

        if ($cut > 0) {
            $node['truncated'] = (int) $cut;
        }

        $this->add($cursor, $node);
    }

    /**
     * @param int $type
     * @param string|int|null $class
     * @param bool $hasChild
     */
    public function enterHash(Cursor $cursor, $type, $class, $hasChild): void
    {
        list($key, $visibility) = $this->keyOf($cursor);

        if ($type === Cursor::HASH_OBJECT) {
            // softRefHandle (the object's handle) is missing on very old VarDumper versions.
            $cursorVars = get_object_vars($cursor);
            $handle = isset($cursorVars['softRefHandle']) ? (int) $cursorVars['softRefHandle'] : 0;

            if ($handle > 0 && isset($this->objectIds[$handle]) && ! $hasChild) {
                $node = ['type' => 'ref', 'id' => $this->objectIds[$handle]];
            } else {
                $id = count($this->objectIds) + 1;
                if ($handle > 0) {
                    $this->objectIds[$handle] = $id;
                }
                $node = ['type' => 'object', 'class' => (string) $class, 'id' => $id, 'children' => []];
            }
        } elseif ($type === Cursor::HASH_RESOURCE) {
            $node = ['type' => 'resource', 'value' => (string) $class, 'children' => []];
        } else {
            $node = ['type' => 'array', 'length' => is_int($class) ? $class : 0, 'children' => []];
        }

        $this->stack[] = ['node' => $node, 'key' => $key, 'visibility' => $visibility];
    }

    /**
     * @param int $type
     * @param string|int|null $class
     * @param bool $hasChild
     * @param int $cut
     */
    public function leaveHash(Cursor $cursor, $type, $class, $hasChild, $cut): void
    {
        $entry = array_pop($this->stack);
        if ($entry === null) {
            return;
        }

        $node = $entry['node'];

        if ($node['type'] === 'object') {
            $node['length'] = count($node['children']) + (int) $cut;
        } elseif ($node['type'] === 'resource' && $node['children'] === []) {
            unset($node['children']);
        }

        if ($cut > 0) {
            $node['truncated'] = (int) $cut;
        }

        $this->attach($node, $entry['key'], $entry['visibility']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function add(Cursor $cursor, array $node): void
    {
        list($key, $visibility) = $this->keyOf($cursor);

        $this->attach($node, $key, $visibility);
    }

    /**
     * @param array<string, mixed> $node
     * @param string|int|null $key
     */
    private function attach(array $node, $key, ?string $visibility): void
    {
        if ($this->stack === []) {
            $this->root = $node;

            return;
        }

        $child = ['key' => $key !== null ? $key : count($this->stack[count($this->stack) - 1]['node']['children'])];
        if ($visibility !== null) {
            $child['visibility'] = $visibility;
        }
        $child['node'] = $node;

        $this->stack[count($this->stack) - 1]['node']['children'][] = $child;
    }

    /**
     * The key of the current item in its parent, and its visibility for object properties.
     * VarDumper mangles keys like PHP: "\0*\0name" protected, "\0Class\0name" private,
     * "\0~\0name" virtual (added by a caster), "\0+\0name" dynamic.
     *
     * @return array{0: string|int|null, 1: string|null}
     */
    private function keyOf(Cursor $cursor): array
    {
        if ($this->stack === []) {
            return [null, null];
        }

        $key = $cursor->hashKey;
        $isObject = $cursor->hashType === Cursor::HASH_OBJECT || $cursor->hashType === Cursor::HASH_RESOURCE;

        if (! $isObject) {
            return [$key, null];
        }

        $key = (string) $key;
        if (isset($key[0]) && $key[0] === "\0") {
            $parts = explode("\0", $key, 3);
            if (count($parts) === 3) {
                $visibility = $parts[1] === '*' ? 'protected' : (in_array($parts[1], ['~', '+'], true) ? 'public' : 'private');

                return [$parts[2], $visibility];
            }
        }

        return [$key, 'public'];
    }
}
