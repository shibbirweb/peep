<?php

namespace ShibbirWeb\Peep\Tests\Unit\Transport;

use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\AvailabilityCache;

final class AvailabilityCacheTest extends TestCase
{
    protected function tear_down()
    {
        AvailabilityCache::reset();

        parent::tear_down();
    }

    public function testEndpointIsUpUntilMarkedDown(): void
    {
        $cache = new AvailabilityCache(30, false);

        $this->assertTrue($cache->isUp('127.0.0.1:1'));

        $cache->markDown('127.0.0.1:1');
        $this->assertFalse($cache->isUp('127.0.0.1:1'));
        $this->assertTrue($cache->isUp('127.0.0.1:2'));

        $cache->markUp('127.0.0.1:1');
        $this->assertTrue($cache->isUp('127.0.0.1:1'));
    }

    public function testDownStateExpires(): void
    {
        $cache = new AvailabilityCache(0, false);

        $cache->markDown('127.0.0.1:1');

        $this->assertTrue($cache->isUp('127.0.0.1:1'));
    }

    public function testDownStateIsSharedWithinTheProcess(): void
    {
        (new AvailabilityCache(30, false))->markDown('127.0.0.1:1');

        $this->assertFalse((new AvailabilityCache(30, false))->isUp('127.0.0.1:1'));
    }
}
