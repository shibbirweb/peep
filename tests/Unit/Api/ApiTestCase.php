<?php

namespace ShibbirWeb\Peep\Tests\Unit\Api;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Peep;
use ShibbirWeb\Peep\PeepFactory;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\AvailabilityCache;

/**
 * Peep cards wired to a recording transport.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * @var RecordingTransport
     */
    protected $transport;

    /**
     * @var PeepFactory
     */
    protected $factory;

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
        $this->factory = new PeepFactory(
            Config::fromArray(['enabled' => true, 'project' => 'api']),
            $this->transport,
            null,
            new AvailabilityCache(30, false)
        );
    }

    protected function tear_down()
    {
        AvailabilityCache::reset();

        parent::tear_down();
    }

    protected function peep(): Peep
    {
        return $this->factory->make();
    }

    /**
     * @phpstan-impure
     * @return list<array<string, mixed>>
     */
    protected function payloads(): array
    {
        return $this->transport->payloads();
    }

    /**
     * @return array<string, mixed>
     */
    protected function onlyPayload(): array
    {
        $payloads = $this->payloads();
        $this->assertCount(1, $payloads);

        return $payloads[0];
    }

    /**
     * @return list<string>
     */
    protected function types(): array
    {
        $types = [];
        foreach ($this->payloads() as $payload) {
            $types[] = $payload['type'];
        }

        return $types;
    }
}
