<?php

namespace ShibbirWeb\Peep\Tests\Unit;

use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Version;

final class VersionTest extends TestCase
{
    public function testVersionIsSemantic(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Version::VERSION);
    }
}
