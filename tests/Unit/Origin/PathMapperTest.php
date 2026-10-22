<?php

namespace ShibbirWeb\Peep\Tests\Unit\Origin;

use ShibbirWeb\Peep\Origin\PathMapper;
use ShibbirWeb\Peep\Tests\TestCase;

final class PathMapperTest extends TestCase
{
    public function testMapsRemotePrefixToLocalPrefix(): void
    {
        $mapper = new PathMapper('/var/www/html/', '/Users/me/shop');

        $this->assertSame('/Users/me/shop/app/User.php', $mapper->map('/var/www/html/app/User.php'));
        $this->assertSame('/Users/me/shop', $mapper->map('/var/www/html'));
    }

    public function testLeavesOtherPathsAlone(): void
    {
        $mapper = new PathMapper('/var/www/html', '/Users/me/shop');

        $this->assertSame('/var/www/html-old/a.php', $mapper->map('/var/www/html-old/a.php'));
        $this->assertSame('/srv/a.php', $mapper->map('/srv/a.php'));
    }

    public function testDoesNothingWithoutBothPaths(): void
    {
        $this->assertSame('/var/www/a.php', (new PathMapper('/var/www', null))->map('/var/www/a.php'));
        $this->assertSame('/var/www/a.php', (new PathMapper(null, '/Users/me'))->map('/var/www/a.php'));
    }
}
