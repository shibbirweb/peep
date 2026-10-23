<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Log;

/**
 * The .log files of a folder, read in pieces so a file of any size opens at once: the newest
 * entries first (reading backwards from the end), older ones on demand, and what was added
 * since an offset while the Logs tab watches the file.
 */
final readonly class LogFiles
{
    public const int DEFAULT_LIMIT = 200;

    public const int MAX_LIMIT = 2000;

    private const int CHUNK_BYTES = 524288;

    private const int MAX_READ_BYTES = 8388608;

    private const string NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.log$/';

    private function __construct() {}

    /**
     * The folder's .log files, most recently changed first. Null when the folder cannot be read.
     *
     * @return list<array{name: string, size: int, modified: float}>|null
     */
    public static function list(string $folder): ?array
    {
        if (! is_dir($folder) || ! is_readable($folder)) {
            return null;
        }

        $names = scandir($folder);
        if ($names === false) {
            return null;
        }

        $files = [];
        foreach ($names as $name) {
            $path = $folder . DIRECTORY_SEPARATOR . $name;

            if (preg_match(self::NAME, $name) === 1 && is_file($path)) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'modified' => (float) filemtime($path)];
            }
        }

        usort($files, static fn(array $a, array $b): int => [$b['modified'], $a['name']] <=> [$a['modified'], $b['name']]);

        return $files;
    }

    /**
     * The path of $name inside $folder, or null when it is not one of the folder's .log files.
     */
    public static function resolve(string $folder, string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }

        $path = $folder . DIRECTORY_SEPARATOR . $name;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * The newest $limit entries that start before byte $before (the end of the file when null).
     * $before is where an entry starts, as "start" of an earlier answer gives it.
     *
     * @return array{entries: list<LogEntry>, start: int, end: int, size: int, more_before: bool}
     */
    public static function before(string $path, ?int $before, int $limit, LogParser $parser): array
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $end = $before === null ? $size : max(0, min($before, $size));
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['entries' => [], 'start' => $end, 'end' => $end, 'size' => $size, 'more_before' => false];
        }

        $buffer = '';
        $position = $end;

        try {
            while ($position > 0) {
                $chunkStart = max(0, $position - self::CHUNK_BYTES);
                fseek($handle, $chunkStart);
                $buffer = (string) fread($handle, max(1, $position - $chunkStart)) . $buffer;
                $position = $chunkStart;

                // One entry more than wanted proves the first wanted one is complete.
                if (count(LogParser::starts($buffer)) > $limit || strlen($buffer) >= self::MAX_READ_BYTES) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        $starts = LogParser::starts($buffer);
        if ($starts === []) {
            return ['entries' => [], 'start' => $end, 'end' => $end, 'size' => $size, 'more_before' => $position > 0];
        }

        $first = $starts[max(0, count($starts) - $limit)];
        $start = $position + $first;

        return [
            'entries' => $parser->parse(substr($buffer, $first), $start),
            'start' => $start,
            'end' => $end,
            'size' => $size,
            'more_before' => $start > 0,
        ];
    }

    /**
     * The entries written after byte $after. When the file got shorter (rotated or cleared),
     * or too much was added to send at once, it starts over from the end: "reset" is then true.
     *
     * @return array{entries: list<LogEntry>, start: int, end: int, size: int, more_before: bool, reset: bool}
     */
    public static function after(string $path, int $after, LogParser $parser): array
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);

        if ($size < $after || $size - $after > self::MAX_READ_BYTES) {
            return [...self::before($path, null, self::DEFAULT_LIMIT, $parser), 'reset' => true];
        }

        $text = $size > $after ? (string) file_get_contents($path, false, null, $after, max(0, $size - $after)) : '';

        // A line still being written ends the read; it comes with the next one.
        $complete = strrpos($text, "\n");
        $text = $complete === false ? '' : substr($text, 0, $complete + 1);

        return [
            'entries' => $parser->parse($text, $after),
            'start' => $after,
            'end' => $after + strlen($text),
            'size' => $size,
            'more_before' => $after > 0,
            'reset' => false,
        ];
    }
}
