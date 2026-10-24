<?php

namespace ShibbirWeb\Peep\Tests\Unit\Envelope;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Context\ExecutionContext;
use ShibbirWeb\Peep\Envelope\MetaFactory;
use ShibbirWeb\Peep\Tests\TestCase;

final class MetaFactoryTest extends TestCase
{
    public function testNoLogFolderByDefault(): void
    {
        $meta = (new MetaFactory(Config::fromArray([]), new ExecutionContext()))->create();

        $this->assertArrayNotHasKey('logs', $meta);
    }

    public function testTheLogFolderIsMappedLikeOrigins(): void
    {
        $config = Config::fromArray([
            'log_path' => '/var/www/html/storage/logs',
            'log_timezone' => 'Europe/Berlin',
            'remote_path' => '/var/www/html',
            'local_path' => '/Users/you/code/shop',
        ]);

        $meta = (new MetaFactory($config, new ExecutionContext()))->create();

        $this->assertSame(['path' => '/Users/you/code/shop/storage/logs', 'timezone' => 'Europe/Berlin'], isset($meta['logs']) ? $meta['logs'] : null);
    }

    public function testTheTimeZoneDefaultsToPhps(): void
    {
        $meta = (new MetaFactory(Config::fromArray(['log_path' => '/app/logs']), new ExecutionContext()))->create();

        $this->assertSame(['path' => '/app/logs', 'timezone' => date_default_timezone_get()], isset($meta['logs']) ? $meta['logs'] : null);
    }
}
