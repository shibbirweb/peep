<?php

namespace ShibbirWeb\Peep\Tests\Unit\Support;

use ShibbirWeb\Peep\Support\Uuid;
use ShibbirWeb\Peep\Tests\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneratesVersion4Uuids(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Uuid::v4()
        );
    }

    public function testUuidsAreUnique(): void
    {
        $uuids = [];
        for ($i = 0; $i < 1000; $i++) {
            $uuids[] = Uuid::v4();
        }

        $this->assertCount(1000, array_unique($uuids));
    }
}
