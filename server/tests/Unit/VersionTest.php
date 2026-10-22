<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Version;

final class VersionTest extends TestCase
{
    public function testVersionIsSemantic(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Version::VERSION);
    }
}
