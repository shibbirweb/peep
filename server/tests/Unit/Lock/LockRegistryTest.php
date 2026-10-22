<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Lock;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Lock\LockRegistry;

final class LockRegistryTest extends TestCase
{
    public function testContinueAndStop(): void
    {
        $locks = new LockRegistry();
        $locks->register('a', 'shop', 'card-1', 100.0);
        $locks->register('b', 'shop', 'card-2', 100.0);

        $this->assertSame(['active' => true, 'stop' => false], $locks->state('a', 101.0));
        $this->assertSame(['a', 'b'], $locks->active(101.0));

        $this->assertTrue($locks->release('a', false));
        $this->assertTrue($locks->release('b', true));
        $this->assertFalse($locks->release('a', true), 'Already released.');

        $this->assertSame(['active' => false, 'stop' => false], $locks->state('a', 102.0));
        $this->assertSame(['active' => false, 'stop' => true], $locks->state('b', 102.0));
        $this->assertSame([], $locks->active(102.0));
    }

    public function testUnknownAndExpiredLocks(): void
    {
        $locks = new LockRegistry();
        $locks->register('a', 'shop', 'card-1', 100.0);

        $this->assertNull($locks->state('missing', 101.0));
        $this->assertFalse($locks->release('missing', false));
        $this->assertNull($locks->state('a', 100.0 + LockRegistry::TTL_SECONDS));
    }
}
