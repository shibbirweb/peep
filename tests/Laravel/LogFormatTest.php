<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

/**
 * What this Laravel version writes to storage/logs, checked against what the viewer's
 * Logs tab reads (server/src/Log/LogParser.php). Runs on every Laravel and PHP pair in CI,
 * so a Laravel or Monolog that changes the line format fails here first.
 */
final class LogFormatTest extends LaravelTestCase
{
    /**
     * The start of an entry, as LogParser::ENTRY_START reads it.
     */
    private const ENTRY_START = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([^\s\[\]]+?)\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): /m';

    /**
     * @var string
     */
    private $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testEntriesStartTheWayTheViewerReadsThem(): void
    {
        $text = $this->write(function ($logger) {
            $logger->info('Signed in', ['user_id' => 18]);
            $logger->warning("Two\nlines");
        });

        $this->assertSame(2, preg_match_all(self::ENTRY_START, $text, $starts));
        $this->assertSame(['INFO', 'WARNING'], $starts[3]);
        $this->assertSame(1, preg_match('/\.INFO: Signed in \{"user_id":18\}/', $text), $text);
        $this->assertSame(1, preg_match('/\.WARNING: Two\nlines/', $text), 'Laravel keeps line breaks in messages: ' . $text);
    }

    public function testExceptionsCarryTheirClassFileAndStack(): void
    {
        $text = $this->write(function ($logger) {
            $logger->error('Payment failed', ['order' => 18, 'exception' => new \RuntimeException('Card declined', 402, new \LogicException('Card expired'))]);
        });

        $this->assertSame(1, preg_match(self::ENTRY_START, $text));
        $this->assertSame(
            1,
            preg_match('/\.ERROR: Payment failed \{"order":18,"exception":"\[object\] \(RuntimeException\(code: 402\): Card declined at .+?:\d+(\)|, )/', $text),
            $text
        );
        $this->assertSame(1, preg_match('/\n\[stacktrace\]\n#0 /', $text), 'Laravel writes the stack trace: ' . $text);

        // Monolog 1 (Laravel 5.5 to 5.8) puts previous exceptions in the same parentheses,
        // Monolog 2 and 3 in "[previous exception]" blocks. The viewer reads both.
        $this->assertSame(1, preg_match('/(, |\[previous exception\] \[object\] \()LogicException\(code: 0\): Card expired at /', $text), $text);
    }

    /**
     * Logs through a channel of its own, set up the way Laravel sets up storage/logs.
     *
     * @param callable(\Psr\Log\LoggerInterface): void $write
     */
    private function write(callable $write): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'peep-laravel-log-');
        $log = $this->laravel()->make('log');

        if ($log instanceof \Illuminate\Log\LogManager) {
            // Laravel 5.6 and later: a "single" channel, as config/logging.php has for storage/logs.
            $this->laravel()->make('config')->set('logging.channels.peep_format', ['driver' => 'single', 'path' => $this->file, 'level' => 'debug']);
            $logger = $log->channel('peep_format');
        } else {
            // Laravel 5.5: the log writer with its own file handler and formatter.
            $log->useFiles($this->file);
            $logger = $log;
        }

        $write($logger);

        return (string) file_get_contents($this->file);
    }
}
