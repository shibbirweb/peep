<?php

namespace ShibbirWeb\Peep\Tests\Unit\Serializer\Caster;

use DateTime;
use LogicException;
use RuntimeException;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Serializer\ValueSerializer;
use ShibbirWeb\Peep\Tests\TestCase;
use stdClass;

final class CasterTest extends TestCase
{
    public function testThrowable(): void
    {
        $node = $this->serialize(new RuntimeException('Failed', 3, new LogicException('Cause')));

        $this->assertSame('Failed', $node['summary']);
        $this->assertSame(['message', 'code', 'file', 'previous'], $this->keys($node));
        $this->assertSame('Cause', $node['children'][3]['node']['summary']);
    }

    public function testClosureSignature(): void
    {
        $closure = function (int $id, ?string $name, $untyped, stdClass ...$rest) {
            return null;
        };

        $node = $this->serialize($closure);

        $this->assertSame('function (int $id, ?string $name, $untyped, stdClass $rest)', $node['summary']);
        $this->assertStringStartsWith(__FILE__ . ':', $node['children'][1]['node']['value']);
    }

    public function testDateTime(): void
    {
        $node = $this->serialize(new DateTime('@0'));

        $this->assertSame('1970-01-01 00:00:00.000000 +00:00', $node['summary']);
        $this->assertSame(0, $node['children'][2]['node']['value']);
    }

    public function testEnum(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Enums need PHP 8.1.');
        }

        // Declared with eval so this file still parses on PHP 7.1.
        if (! enum_exists('PeepTestSuit')) { // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.enum_existsFound
            eval('enum PeepTestSuit: string { case Hearts = "H"; }');
        }

        $node = $this->serialize(constant('PeepTestSuit::Hearts'));

        $this->assertSame('PeepTestSuit::Hearts', $node['summary']);
        $this->assertSame('H', $node['children'][1]['node']['value']);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function serialize($value): array
    {
        return ValueSerializer::fromConfig(Config::fromArray([]))->serialize($value);
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function keys(array $node): array
    {
        $keys = [];
        foreach ($node['children'] as $child) {
            $keys[] = $child['key'];
        }

        return $keys;
    }
}
