<?php

namespace ShibbirWeb\Peep\Tests\Unit\Origin;

use ShibbirWeb\Peep\Origin\Origin;
use ShibbirWeb\Peep\Origin\OriginResolver;
use ShibbirWeb\Peep\Origin\PathMapper;
use ShibbirWeb\Peep\Tests\TestCase;

final class OriginResolverTest extends TestCase
{
    public function testPointsAtTheCallerOutsidePeep(): void
    {
        $resolver = new OriginResolver(new PathMapper(null, null));

        $line = __LINE__ + 1;
        $origin = $resolver->resolve();

        $this->assertInstanceOf(Origin::class, $origin);
        $this->assertSame(__FILE__, $origin->file());
        $this->assertSame($line, $origin->line());
        $this->assertSame(__FUNCTION__, $origin->functionName());
        $this->assertSame(self::class, $origin->className());
    }

    public function testSkipsRegisteredPrefixes(): void
    {
        $resolver = new OriginResolver(new PathMapper(null, null));
        $resolver->skip(__FILE__);

        $origin = $resolver->resolve();

        $this->assertInstanceOf(Origin::class, $origin);
        $this->assertNotSame(__FILE__, $origin->file());
    }

    public function testMapsPaths(): void
    {
        $resolver = new OriginResolver(new PathMapper(dirname(__FILE__), '/Users/me/project/tests'));

        $origin = $resolver->resolve();

        $this->assertInstanceOf(Origin::class, $origin);
        $this->assertSame('/Users/me/project/tests/' . basename(__FILE__), $origin->file());
    }

    public function testToArray(): void
    {
        $origin = new Origin('/app/a.php', 12, 'store', 'App\\UserService');

        $this->assertSame(
            ['file' => '/app/a.php', 'line' => 12, 'function' => 'store', 'class' => 'App\\UserService'],
            $origin->toArray()
        );
    }
}
