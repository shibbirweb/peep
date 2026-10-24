<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Log\LogEntry;
use ShibbirWeb\PeepServer\Log\LogFiles;
use ShibbirWeb\PeepServer\Log\LogParser;

final class LogFilesTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/peep-logs-' . bin2hex(random_bytes(6));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->folder);
    }

    public function testListsLogFilesNewestFirst(): void
    {
        file_put_contents($this->folder . '/laravel-2026-10-22.log', 'a');
        touch($this->folder . '/laravel-2026-10-22.log', time() - 3600);
        file_put_contents($this->folder . '/laravel.log', 'bb');
        file_put_contents($this->folder . '/notes.txt', 'not a log');

        $files = LogFiles::list($this->folder);

        $this->assertNotNull($files);
        $this->assertSame(['laravel.log', 'laravel-2026-10-22.log'], array_column($files, 'name'));
        $this->assertSame(2, $files[0]['size']);
        $this->assertNull(LogFiles::list($this->folder . '/missing'));
    }

    public function testOnlyLogFilesOfTheFolderResolve(): void
    {
        file_put_contents($this->folder . '/laravel.log', 'x');

        $this->assertSame($this->folder . '/laravel.log', LogFiles::resolve($this->folder, 'laravel.log'));
        $this->assertNull(LogFiles::resolve($this->folder, '../laravel.log'));
        $this->assertNull(LogFiles::resolve($this->folder, '/etc/passwd'));
        $this->assertNull(LogFiles::resolve($this->folder, 'notes.txt'));
        $this->assertNull(LogFiles::resolve($this->folder, 'gone.log'));
    }

    public function testTheNewestEntriesComeFirstAndOlderOnDemand(): void
    {
        // About 1.5 MB, so reading backwards crosses several chunks.
        $path = $this->folder . '/laravel.log';
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        for ($index = 0; $index < 6000; $index++) {
            fwrite($handle, sprintf("[2026-10-23 10:%02d:%02d] local.INFO: Entry %d %s\n", intdiv($index, 60) % 60, $index % 60, $index, str_repeat('x', 220)));
        }
        fclose($handle);

        $newest = LogFiles::before($path, null, 200, new LogParser());

        $this->assertCount(200, $newest['entries']);
        $this->assertSame('Entry 5800 ' . str_repeat('x', 220), $newest['entries'][0]->message);
        $this->assertSame('Entry 5999 ' . str_repeat('x', 220), $newest['entries'][199]->message);
        $this->assertSame((int) filesize($path), $newest['end']);
        $this->assertSame($newest['entries'][0]->offset, $newest['start']);
        $this->assertTrue($newest['more_before']);

        $older = LogFiles::before($path, $newest['start'], 3000, new LogParser());
        $this->assertCount(2000, $older['entries'], 'At most MAX_LIMIT at once.');
        $this->assertSame('Entry 5799 ' . str_repeat('x', 220), $older['entries'][1999]->message);

        // "before" is where a loaded entry starts: here the second one.
        $first = LogFiles::before($path, strlen(sprintf("[2026-10-23 10:00:00] local.INFO: Entry 0 %s\n", str_repeat('x', 220))), 10, new LogParser());
        $this->assertSame(['Entry 0 ' . str_repeat('x', 220)], array_map(static fn(LogEntry $entry): string => $entry->message, $first['entries']));
        $this->assertFalse($first['more_before']);
    }

    public function testWatchingReadsWhatWasAddedAndWaitsForWholeLines(): void
    {
        $path = $this->folder . '/laravel.log';
        file_put_contents($path, "[2026-10-23 10:15:01] local.INFO: One\n");
        $end = LogFiles::before($path, null, 50, new LogParser())['end'];

        file_put_contents($path, "[2026-10-23 10:15:02] local.ERROR: Two\n[2026-10-23 10:15:03] local.INFO: Thr", FILE_APPEND);
        $added = LogFiles::after($path, $end, new LogParser());

        $this->assertFalse($added['reset']);
        $this->assertSame(['Two'], array_map(static fn(LogEntry $entry): string => $entry->message, $added['entries']));
        $this->assertSame($end + strlen("[2026-10-23 10:15:02] local.ERROR: Two\n"), $added['end'], 'The half-written line waits.');

        file_put_contents($path, "ee\n", FILE_APPEND);
        $rest = LogFiles::after($path, $added['end'], new LogParser());
        $this->assertSame(['Three'], array_map(static fn(LogEntry $entry): string => $entry->message, $rest['entries']));

        $none = LogFiles::after($path, $rest['end'], new LogParser());
        $this->assertSame([], $none['entries']);
    }

    public function testAShorterFileStartsOver(): void
    {
        $path = $this->folder . '/laravel.log';
        file_put_contents($path, "[2026-10-23 10:15:01] local.INFO: After rotation\n");

        $result = LogFiles::after($path, 100000, new LogParser());

        $this->assertTrue($result['reset']);
        $this->assertSame('After rotation', $result['entries'][0]->message);
    }
}
