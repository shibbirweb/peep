<?php

namespace ShibbirWeb\Peep\Support;

/**
 * A few lines of source code around a line, for exceptions.
 */
final class SourceSnippet
{
    private const MAX_FILE_BYTES = 2097152;

    private function __construct()
    {
    }

    /**
     * @return array{start: int, highlight: int, lines: list<string>}|null
     */
    public static function around(string $file, int $line, int $radius = 5): ?array
    {
        if ($line < 1 || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $size = filesize($file);
        if (! is_int($size) || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines) || $line > count($lines)) {
            return null;
        }

        $start = max(1, $line - $radius);
        $selected = array_slice($lines, $start - 1, $radius * 2 + 1);

        return [
            'start' => $start,
            'highlight' => $line,
            'lines' => array_map('strval', $selected),
        ];
    }
}
