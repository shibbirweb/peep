<?php

namespace ShibbirWeb\Peep\Tests\Unit\Serializer;

use ArrayObject;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Serializer\ValueSerializer;
use ShibbirWeb\Peep\Tests\Fixture\DebugInfoFixture;
use ShibbirWeb\Peep\Tests\Fixture\LinkedNodeFixture;
use ShibbirWeb\Peep\Tests\Fixture\VisibilityFixture;
use ShibbirWeb\Peep\Tests\TestCase;
use stdClass;

final class ValueSerializerTest extends TestCase
{
    public function testScalars(): void
    {
        $serializer = $this->serializer();

        $this->assertSame(['type' => 'null'], $serializer->serialize(null));
        $this->assertSame(['type' => 'bool', 'value' => true], $serializer->serialize(true));
        $this->assertSame(['type' => 'int', 'value' => 42], $serializer->serialize(42));
        $this->assertSame(['type' => 'float', 'value' => 1.5], $serializer->serialize(1.5));
        $this->assertSame(['type' => 'string', 'value' => 'héllo', 'length' => 5], $serializer->serialize('héllo'));
    }

    public function testSpecialNumbers(): void
    {
        $serializer = $this->serializer();

        $this->assertSame(['type' => 'float', 'value' => 'INF'], $serializer->serialize(INF));
        $this->assertSame(['type' => 'float', 'value' => '-INF'], $serializer->serialize(-INF));
        $this->assertSame(['type' => 'float', 'value' => 'NAN'], $serializer->serialize(NAN));
        $this->assertSame(['type' => 'int', 'value' => '9007199254740993'], $serializer->serialize(9007199254740993));
        $this->assertSame(['type' => 'int', 'value' => 9007199254740991], $serializer->serialize(9007199254740991));
    }

    public function testLongStringsAreCutByCharacter(): void
    {
        $node = $this->serializer(['max_string_length' => 3])->serialize('ééééé');

        $this->assertSame(['type' => 'string', 'value' => 'ééé', 'length' => 5, 'truncated' => 2], $node);
    }

    public function testInvalidUtf8IsSentAsBinary(): void
    {
        $node = $this->serializer()->serialize("\xff\xfe\x00abc");

        $this->assertSame('binary', $node['type']);
        $this->assertSame(base64_encode("\xff\xfe\x00abc"), $node['value']);
        $this->assertSame(6, $node['length']);
    }

    public function testArraysKeepKeysAndRespectTheItemLimit(): void
    {
        $node = $this->serializer(['max_items' => 2])->serialize(['a' => 1, 5 => 'b', 'c' => true]);

        $this->assertSame('array', $node['type']);
        $this->assertSame(3, $node['length']);
        $this->assertSame(1, $node['truncated']);
        $this->assertSame('a', $node['children'][0]['key']);
        $this->assertSame(['type' => 'int', 'value' => 1], $node['children'][0]['node']);
        $this->assertSame(5, $node['children'][1]['key']);
        $this->assertCount(2, $node['children']);
    }

    public function testDepthLimit(): void
    {
        $node = $this->serializer(['max_depth' => 2])->serialize(['level1' => ['level2' => ['level3' => 1]]]);

        $level2 = $node['children'][0]['node']['children'][0]['node'];
        $this->assertSame(['type' => 'max_depth', 'length' => 1], $level2);
    }

    public function testObjectPropertiesWithVisibilityAreReadWithoutGetters(): void
    {
        $node = $this->serializer()->serialize(new VisibilityFixture());

        $this->assertSame('object', $node['type']);
        $this->assertSame(VisibilityFixture::class, $node['class']);
        $this->assertSame(1, $node['id']);

        $children = [];
        foreach ($node['children'] as $child) {
            $children[$child['key']] = $child;
        }

        $this->assertSame('public', $children['visible']['visibility']);
        $this->assertSame('protected', $children['guarded']['visibility']);
        $this->assertSame('protected value', $children['guarded']['node']['value']);
        $this->assertSame('private', $children['hidden']['visibility']);
        $this->assertSame('private value', $children['hidden']['node']['value']);
    }

    public function testSensitiveKeysAreRedacted(): void
    {
        $serializer = $this->serializer();

        $array = $serializer->serialize(['PASSWORD' => 'secret', 'name' => 'Ada']);
        $this->assertSame(['type' => 'string', 'value' => '***', 'redacted' => true], $array['children'][0]['node']);
        $this->assertSame('Ada', $array['children'][1]['node']['value']);

        $object = $serializer->serialize(new VisibilityFixture());
        foreach ($object['children'] as $child) {
            if ($child['key'] === 'password') {
                $this->assertTrue($child['node']['redacted']);
            }
        }
    }

    public function testLoopsAndRepeatsBecomeReferences(): void
    {
        $first = new LinkedNodeFixture('first');
        $second = new LinkedNodeFixture('second');
        $first->next = $second;
        $second->next = $first;

        $node = $this->serializer()->serialize([$first, $second]);

        $firstNode = $node['children'][0]['node'];
        $secondInsideFirst = $firstNode['children'][1]['node'];
        $this->assertSame('object', $secondInsideFirst['type']);
        $this->assertSame(['type' => 'ref', 'id' => 1], $secondInsideFirst['children'][1]['node']);
        $this->assertSame(['type' => 'ref', 'id' => 2], $node['children'][1]['node']);
    }

    public function testNodeLimitStopsLargeValues(): void
    {
        $node = $this->serializer(['max_nodes' => 10, 'max_items' => 1000])->serialize(range(1, 100));

        $this->assertSame(100, $node['length']);
        $this->assertLessThan(100, count($node['children']));
        $this->assertSame(100 - count($node['children']), $node['truncated']);
    }

    public function testDebugInfoIsUsedWhenAvailable(): void
    {
        $node = $this->serializer()->serialize(new DebugInfoFixture());

        $this->assertSame('shown', $node['children'][0]['key']);
        $this->assertSame('from __debugInfo internal', $node['children'][0]['node']['value']);
    }

    public function testThrowingDebugInfoFallsBackToProperties(): void
    {
        $node = $this->serializer()->serialize(new DebugInfoFixture(true));

        $keys = [];
        foreach ($node['children'] as $child) {
            $keys[] = $child['key'];
        }

        $this->assertSame(['internal', 'throwing'], $keys);
    }

    public function testResources(): void
    {
        $serializer = $this->serializer();
        $handle = fopen('php://memory', 'r');
        $this->assertIsResource($handle);

        $this->assertSame(['type' => 'resource', 'value' => 'stream'], $serializer->serialize($handle));

        fclose($handle);
        $this->assertSame(['type' => 'resource', 'value' => 'closed'], $serializer->serialize($handle));
    }

    public function testDynamicAndSpecialObjects(): void
    {
        $object = new stdClass();
        $object->{'0'} = 'numeric name';
        $object->name = 'dynamic';

        $node = $this->serializer()->serialize($object);
        $this->assertSame('0', $node['children'][0]['key']);
        $this->assertSame('name', $node['children'][1]['key']);

        $arrayObject = $this->serializer()->serialize(new ArrayObject(['x' => 1]));
        $this->assertSame(ArrayObject::class, $arrayObject['class']);
    }

    public function testEveryNodeEncodesAsJson(): void
    {
        $node = $this->serializer()->serialize([1.0, INF, "\xff", new VisibilityFixture(), [null]]);

        $this->assertIsString(json_encode($node, JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function serializer(array $options = []): ValueSerializer
    {
        return ValueSerializer::fromConfig(Config::fromArray($options));
    }
}
