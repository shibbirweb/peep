<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Log\FolderBrowser;

final class FolderBrowserTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/peep-browse-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/shop/storage/logs', 0777, true);
        mkdir($this->root . '/blog');
        mkdir($this->root . '/.git');
        file_put_contents($this->root . '/laravel.log', 'a');
        file_put_contents($this->root . '/notes.txt', 'b');
    }

    protected function tearDown(): void
    {
        foreach (['/laravel.log', '/notes.txt'] as $file) {
            unlink($this->root . $file);
        }
        foreach (['/shop/storage/logs', '/shop/storage', '/shop', '/blog', '/.git', ''] as $folder) {
            rmdir($this->root . $folder);
        }
    }

    public function testListsFoldersAndCountsLogFiles(): void
    {
        $listing = FolderBrowser::browse($this->root);

        $this->assertNotNull($listing);
        $this->assertSame($this->root, $listing['path']);
        $this->assertSame(dirname($this->root), $listing['parent']);
        $this->assertSame(1, $listing['logs']);
        $this->assertNull($listing['laravel_logs']);
        $this->assertSame([
            ['name' => 'blog', 'path' => $this->root . '/blog', 'laravel' => false],
            ['name' => 'shop', 'path' => $this->root . '/shop', 'laravel' => true],
        ], $listing['folders'], 'Hidden folders and files are left out; Laravel apps are marked.');
        $this->assertFalse($listing['more']);
    }

    public function testOffersAnAppsStorageLogs(): void
    {
        $listing = FolderBrowser::browse($this->root . '/shop/');

        $this->assertSame($this->root . '/shop/storage/logs', $listing['laravel_logs'] ?? null);
    }

    public function testFallsBackToTheNearestFolderThenHome(): void
    {
        $this->assertSame($this->root . '/blog', FolderBrowser::browse($this->root . '/blog/no/such')['path'] ?? null);

        $shop = $this->root . '/shop';
        $home = static fn(string $name): string|false => $name === 'HOME' ? $shop : false;
        $this->assertSame($this->root . '/shop', FolderBrowser::browse(null, $home)['path'] ?? null);
        $this->assertSame($this->root . '/shop', FolderBrowser::browse('  ', $home)['home'] ?? null);
    }

    public function testTheRootHasNoParent(): void
    {
        $listing = FolderBrowser::browse('/');

        $this->assertNotNull($listing);
        $this->assertNull($listing['parent']);
    }
}
