<?php

namespace ShibbirWeb\Peep\Tests\Unit\Config;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromArray([]);

        $this->assertFalse($config->enabled());
        $this->assertSame('127.0.0.1', $config->host());
        $this->assertSame(23517, $config->port());
        $this->assertSame('127.0.0.1:23517', $config->endpointKey());
        $this->assertSame(50, $config->connectTimeoutMs());
        $this->assertSame(500, $config->timeoutMs());
        $this->assertSame('default', $config->project());
        $this->assertNull($config->remotePath());
        $this->assertNull($config->localPath());
        $this->assertSame(5, $config->maxDepth());
        $this->assertSame(100, $config->maxItems());
        $this->assertSame(10000, $config->maxStringLength());
        $this->assertSame(5000, $config->maxNodes());
        $this->assertContains('password', $config->redactKeys());
    }

    public function testReadsValidValuesIncludingStringsFromTheEnvironment(): void
    {
        $config = Config::fromArray([
            'enabled' => 'true',
            'host' => ' host.docker.internal ',
            'port' => '9000',
            'project' => 'shop',
            'remote_path' => '/var/www',
            'local_path' => '/Users/me/shop',
            'redact_keys' => ['Card_Number', '', 42],
        ]);

        $this->assertTrue($config->enabled());
        $this->assertSame('host.docker.internal', $config->host());
        $this->assertSame(9000, $config->port());
        $this->assertSame('shop', $config->project());
        $this->assertSame('/var/www', $config->remotePath());
        $this->assertSame('/Users/me/shop', $config->localPath());
        $this->assertSame(['card_number'], $config->redactKeys());
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $config = Config::fromArray([
            'enabled' => 'maybe',
            'host' => '',
            'port' => 70000,
            'timeout_ms' => 'fast',
            'project' => '   ',
            'max_depth' => 0,
            'redact_keys' => 'password',
        ]);

        $this->assertFalse($config->enabled());
        $this->assertSame('127.0.0.1', $config->host());
        $this->assertSame(23517, $config->port());
        $this->assertSame(500, $config->timeoutMs());
        $this->assertSame('default', $config->project());
        $this->assertSame(5, $config->maxDepth());
        $this->assertSame(Config::defaults()['redact_keys'], $config->redactKeys());
    }

    public function testBooleanParsing(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 'yes', 'on', ' On '] as $value) {
            $this->assertTrue(Config::toBool($value, false), var_export($value, true));
        }

        foreach ([false, 0, '0', 'false', 'no', 'off', ''] as $value) {
            $this->assertFalse(Config::toBool($value, true), var_export($value, true));
        }

        $this->assertTrue(Config::toBool('unknown', true));
        $this->assertFalse(Config::toBool(null, false));
    }

    public function testWithEnabledReturnsACopy(): void
    {
        $config = Config::fromArray([]);
        $enabled = $config->withEnabled(true);

        $this->assertFalse($config->enabled());
        $this->assertTrue($enabled->enabled());
    }
}
