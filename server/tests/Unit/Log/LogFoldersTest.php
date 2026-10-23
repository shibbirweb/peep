<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Log\LogFolders;
use ShibbirWeb\PeepServer\Settings\Settings;
use ShibbirWeb\PeepServer\Settings\SettingsStore;

final class LogFoldersTest extends TestCase
{
    public function testTheAppsFolderIsKeptUntilOneIsChosen(): void
    {
        $store = new SettingsStore();
        $folders = new LogFolders($store);

        $events = $folders->reported('shop', ['path' => '/app/storage/logs', 'timezone' => 'UTC']);
        $this->assertSame('logs.folders', $events[0]->name);
        $this->assertSame([], $folders->reported('shop', ['path' => '/app/storage/logs', 'timezone' => 'UTC']), 'Nothing changed, nothing published.');

        $folders->choose('shop', '/elsewhere');
        $folders->reported('shop', ['path' => '/app/storage/logs', 'timezone' => 'Europe/Berlin']);
        $this->assertSame(['path' => '/elsewhere', 'timezone' => 'Europe/Berlin', 'manual' => true], $folders->for('shop'), 'A chosen folder wins; the time zone follows the app.');

        $folders->choose('shop', null);
        $this->assertNull($folders->for('shop'));
        $folders->reported('shop', ['path' => '/app/storage/logs', 'timezone' => 'UTC']);
        $this->assertSame(['path' => '/app/storage/logs', 'timezone' => 'UTC', 'manual' => false], $folders->for('shop'));

        $this->assertSame([['project' => 'shop', 'path' => '/app/storage/logs', 'timezone' => 'UTC', 'manual' => false, 'exists' => false]], $folders->all());
        $this->assertSame($store->settings->logFolders, ['shop' => ['path' => '/app/storage/logs', 'timezone' => 'UTC', 'manual' => false]], 'Kept in the settings.');
    }

    public function testSettingsKeepOnlyValidFolders(): void
    {
        $settings = Settings::fromArray(['log_folders' => [
            'shop' => ['path' => '/app/storage/logs', 'timezone' => 'UTC', 'manual' => true],
            'blog' => ['path' => ''],
            'api' => 'not a folder',
        ]]);

        $this->assertSame(['shop' => ['path' => '/app/storage/logs', 'timezone' => 'UTC', 'manual' => true]], $settings->logFolders);
        $this->assertArrayNotHasKey('log_folders', new SettingsStore($settings)->public());
    }

    public function testClearedMarks(): void
    {
        $folders = new LogFolders(new SettingsStore());
        $folders->reported('shop', ['path' => '/a', 'timezone' => 'UTC']);
        $folders->reported('blog', ['path' => '/b', 'timezone' => 'UTC']);

        $folders->markCleared(null, 50.0);
        $folders->markCleared('shop', 60.0);

        $this->assertSame(60.0, $folders->clearedAt('shop'));
        $this->assertSame(50.0, $folders->clearedAt('blog'));
        $this->assertNull($folders->clearedAt('other'));
    }
}
