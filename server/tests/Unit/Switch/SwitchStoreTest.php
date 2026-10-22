<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Switch;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Switch\SwitchStore;

final class SwitchStoreTest extends TestCase
{
    public function testDefaultsPerProject(): void
    {
        $store = new SwitchStore();

        $this->assertSame(SwitchStore::DEFAULTS, $store->for('shop'));
        $this->assertSame(['switches' => SwitchStore::DEFAULTS, 'switches_ttl' => 5], $store->answer('shop'));
    }

    public function testUpdateKeepsOnlyValidValues(): void
    {
        $store = new SwitchStore();

        $switches = $store->update('shop', ['queries' => 'on', 'slow_queries' => 'maybe', 'slow_query_ms' => 120, 'unknown' => 'on']);

        $this->assertSame('on', $switches['queries']);
        $this->assertSame('default', $switches['slow_queries']);
        $this->assertSame(120, $switches['slow_query_ms']);
        $this->assertArrayNotHasKey('unknown', $switches);
        $this->assertSame(SwitchStore::DEFAULTS, $store->for('blog'), 'Projects are separate.');
    }
}
