<?php

namespace ShibbirWeb\Peep\Tests\Unit\Bridge;

use ShibbirWeb\Peep\Bridge\VarDumper\DumpHandler;
use ShibbirWeb\Peep\Bridge\VarDumper\VarDumperSerializer;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Tests\Fixture\LinkedNodeFixture;
use ShibbirWeb\Peep\Tests\Fixture\VisibilityFixture;
use ShibbirWeb\Peep\Tests\Unit\Api\ApiTestCase;
use Symfony\Component\VarDumper\VarDumper;

final class VarDumperBridgeTest extends ApiTestCase
{
    protected function tear_down()
    {
        VarDumper::setHandler(null);

        parent::tear_down();
    }

    public function testScalarsAndArrays(): void
    {
        $serializer = new VarDumperSerializer(Config::fromArray([]));

        $this->assertSame(['type' => 'int', 'value' => 42], $serializer->serialize(42));
        $this->assertSame(['type' => 'null'], $serializer->serialize(null));
        $this->assertSame(['type' => 'bool', 'value' => true], $serializer->serialize(true));
        $this->assertSame(['type' => 'string', 'value' => 'héllo', 'length' => 5], $serializer->serialize('héllo'));

        $array = $serializer->serialize(['a' => 1, 'list' => [1, 2]]);
        $this->assertSame('array', $array['type']);
        $this->assertSame('a', $array['children'][0]['key']);
        $this->assertSame('list', $array['children'][1]['key']);
        $this->assertSame(1, $array['children'][1]['node']['children'][1]['key']);
    }

    public function testObjectsWithVisibilityAndRepeats(): void
    {
        $serializer = new VarDumperSerializer(Config::fromArray([]));

        $object = $serializer->serialize(new VisibilityFixture());
        $this->assertSame('object', $object['type']);
        $this->assertSame(VisibilityFixture::class, $object['class']);

        $visibility = [];
        foreach ($object['children'] as $child) {
            $visibility[$child['key']] = $child['visibility'];
        }
        $this->assertSame('public', $visibility['visible']);
        $this->assertSame('protected', $visibility['guarded']);
        $this->assertSame('private', $visibility['hidden']);

        $node = new LinkedNodeFixture('loop');
        $node->next = $node;
        $loop = $serializer->serialize($node);
        $this->assertSame('ref', $loop['children'][1]['node']['type']);
    }

    public function testFactoryUsesItWhenConfigured(): void
    {
        $this->assertSame('var-dumper', Config::fromArray(['serializer' => 'var-dumper'])->serializer());
        $this->assertSame('peep', Config::fromArray(['serializer' => 'other'])->serializer());
    }

    public function testDumpGoesToPeep(): void
    {
        DumpHandler::register(false, $this->factory);

        VarDumper::dump(['dumped' => true]);

        $payload = $this->payloads()[0];
        $this->assertSame('log', $payload['type']);
        $this->assertSame('dumped', $payload['content']['values'][0]['children'][0]['key']);
        $this->assertSame(__FILE__, $payload['origin']['file']);
    }
}
