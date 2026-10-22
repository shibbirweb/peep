<?php

namespace ShibbirWeb\Peep\Tests\Unit;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\PeepFactory;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Tests\Fixture\CountingSerializer;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\AvailabilityCache;

final class PeepTest extends TestCase
{
    /**
     * @var RecordingTransport
     */
    private $transport;

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
    }

    protected function tear_down()
    {
        AvailabilityCache::reset();

        parent::tear_down();
    }

    public function testSendsSeveralValuesAsOneLogPayloadWithTheCallersOrigin(): void
    {
        $line = __LINE__ + 1;
        $this->factory()->make()->send('hello', ['a' => 1], null);

        $payloads = $this->transport->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame('log', $payloads[0]['type']);
        $this->assertCount(3, $payloads[0]['content']['values']);
        $this->assertSame('hello', $payloads[0]['content']['values'][0]['value']);
        $this->assertSame('array', $payloads[0]['content']['values'][1]['type']);
        $this->assertSame('null', $payloads[0]['content']['values'][2]['type']);
        $this->assertSame(__FILE__, $payloads[0]['origin']['file']);
        $this->assertSame($line, $payloads[0]['origin']['line']);
        $this->assertSame(__FUNCTION__, $payloads[0]['origin']['function']);
    }

    public function testChainedCallsUpdateTheSameCard(): void
    {
        $peep = $this->factory()->make()->send('value')->red()->label('User')->large()->small();

        $cards = [];
        $types = [];
        foreach ($this->transport->requests as $request) {
            $cards[] = $request['body']['card'];
            $types[] = $request['body']['payloads'][0]['type'];
        }

        $this->assertSame(['log', 'color', 'label', 'size', 'size'], $types);
        $this->assertSame([$peep->card()], array_values(array_unique($cards)));

        $payloads = $this->transport->payloads();
        $this->assertSame(['color' => 'red'], $payloads[1]['content']);
        $this->assertNull($payloads[1]['origin']);
        $this->assertSame(['label' => 'User'], $payloads[2]['content']);
        $this->assertSame(['size' => 'large'], $payloads[3]['content']);
        $this->assertSame(['size' => 'small'], $payloads[4]['content']);
    }

    public function testEveryColor(): void
    {
        $this->factory()->make()->red()->green()->blue()->orange()->purple()->gray()->yellow()->color('pink');

        $colors = [];
        foreach ($this->transport->payloads() as $payload) {
            $colors[] = $payload['content']['color'];
        }

        $this->assertSame(['red', 'green', 'blue', 'orange', 'purple', 'gray', 'yellow'], $colors);
    }

    public function testEachPeepIsANewCard(): void
    {
        $factory = $this->factory();

        $this->assertNotSame($factory->make()->card(), $factory->make()->card());
    }

    public function testFormatPayloads(): void
    {
        $this->factory()->make()
            ->text("  keep   spaces\n")
            ->html('<b>bold</b>')
            ->json('{"a":1}')
            ->table(['Name' => 'Ada', 'Role' => 'Admin'], 'User');

        $payloads = $this->transport->payloads();

        $this->assertSame(['text' => "  keep   spaces\n"], $payloads[0]['content']);
        $this->assertSame(['html' => '<b>bold</b>'], $payloads[1]['content']);
        $this->assertSame(['json' => '{"a":1}'], $payloads[2]['content']);
        $this->assertSame('table', $payloads[3]['type']);
        $this->assertSame('User', $payloads[3]['content']['label']);
        $this->assertSame('array', $payloads[3]['content']['rows']['type']);

        foreach ($payloads as $payload) {
            $this->assertSame(__FILE__, $payload['origin']['file']);
        }
    }

    public function testDisabledPeepSerializesAndSendsNothing(): void
    {
        $serializer = new CountingSerializer();
        $peep = $this->factory(false, $serializer)->make();

        $peep->send(['big' => 'value'])->table(['a' => 1])->text('x')->red()->label('y');

        $this->assertTrue($peep->disabled());
        $this->assertSame(0, $serializer->calls);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testUnreachableViewerStopsAllWork(): void
    {
        $serializer = new CountingSerializer();
        $factory = $this->factory(true, $serializer);
        $this->transport->failWith = new TransportException('refused');

        $factory->make()->send('first');
        $factory->make()->send('second')->table(['a' => 1])->red();

        $this->assertSame(1, $serializer->calls, 'Only the call that found the viewer down does any work.');
    }

    public function testEnableAndDisableApplyToEveryCardOfTheFactory(): void
    {
        $factory = $this->factory(false);

        $factory->make()->enable();
        $this->assertTrue($factory->make()->enabled());
        $factory->make()->send('sent');

        $factory->make()->disable();
        $factory->make()->send('not sent');

        $this->assertCount(1, $this->transport->requests);
    }

    public function testSendWithoutValuesSendsNothing(): void
    {
        $this->factory()->make()->send();

        $this->assertCount(0, $this->transport->requests);
    }

    private function factory(bool $enabled = true, ?SerializerInterface $serializer = null): PeepFactory
    {
        return new PeepFactory(
            Config::fromArray(['enabled' => $enabled, 'project' => 'shop']),
            $this->transport,
            $serializer,
            new AvailabilityCache(30, false)
        );
    }
}
