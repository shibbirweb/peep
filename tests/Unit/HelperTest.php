<?php

namespace ShibbirWeb\Peep\Tests\Unit;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Peep;
use ShibbirWeb\Peep\PeepFactory;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\AvailabilityCache;

final class HelperTest extends TestCase
{
    /**
     * @var RecordingTransport
     */
    private $transport;

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
        PeepFactory::setInstance(new PeepFactory(
            Config::fromArray(['enabled' => true]),
            $this->transport,
            null,
            new AvailabilityCache(30, false)
        ));
    }

    protected function tear_down()
    {
        PeepFactory::setInstance(null);
        AvailabilityCache::reset();

        parent::tear_down();
    }

    public function testHelperIsDefined(): void
    {
        $this->assertTrue(function_exists('peep'));
    }

    public function testHelperSendsWithTheLineOfTheCall(): void
    {
        $line = __LINE__ + 1;
        $peep = peep('from helper', 42);

        $this->assertInstanceOf(Peep::class, $peep);
        $payloads = $this->transport->payloads();
        $this->assertCount(1, $payloads);
        $this->assertCount(2, $payloads[0]['content']['values']);
        $this->assertSame(__FILE__, $payloads[0]['origin']['file']);
        $this->assertSame($line, $payloads[0]['origin']['line']);
    }

    public function testHelperWithoutArgumentsOnlyReturnsTheCard(): void
    {
        $line = __LINE__ + 1;
        peep()->text('chained');

        $payloads = $this->transport->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame('text', $payloads[0]['type']);
        $this->assertSame($line, $payloads[0]['origin']['line']);
    }

    public function testSharedFactoryIsBuiltFromTheEnvironmentWhenNoneIsSet(): void
    {
        PeepFactory::setInstance(null);

        $factory = PeepFactory::instance();

        $this->assertSame($factory, PeepFactory::instance());
    }
}
