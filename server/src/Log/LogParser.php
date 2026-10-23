<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Log;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Splits Monolog line-format text (Laravel's default) into entries:
 *
 *     [2026-10-23 10:15:02] local.ERROR: Payment failed {"order":18,"exception":"[object] (RuntimeException(code: 0): ... at /app/Pay.php:12)
 *     [stacktrace]
 *     #0 /app/Http/Controllers/OrderController.php(42): App\Pay->charge()
 *     #1 {main}
 *     "} []
 *
 * Laravel lets line breaks into the context JSON, so an entry runs until the next line that
 * starts a new one. The context and extra JSON are decoded, and a Laravel exception string is
 * split into class, message, file, line and stack frames.
 */
final readonly class LogParser
{
    public const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * The start of an entry: "[date] channel.LEVEL: ".
     */
    public const string ENTRY_START = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([^\s\[\]]+?)\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): /m';

    private const int MAX_JSON_TRIES = 20;

    private const int MAX_JSON_BYTES = 1048576;

    private DateTimeZone $timezone;

    /**
     * @param string $timezone Time zone of times written without an offset (the app's).
     */
    public function __construct(string $timezone = 'UTC')
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (\Exception) {
            $this->timezone = new DateTimeZone('UTC');
        }
    }

    /**
     * Byte offsets (within $text) where entries start.
     *
     * @return list<int>
     */
    public static function starts(string $text): array
    {
        preg_match_all(self::ENTRY_START, $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn(array $match): int => $match[1], $matches[0]);
    }

    /**
     * @param int $baseOffset Byte offset of $text in the file, so entries carry their own.
     * @return list<LogEntry>
     */
    public function parse(string $text, int $baseOffset = 0): array
    {
        $starts = self::starts($text);
        $entries = [];

        foreach ($starts as $index => $start) {
            $end = $starts[$index + 1] ?? strlen($text);
            $entries[] = $this->entry(substr($text, $start, $end - $start), $baseOffset + $start);
        }

        return $entries;
    }

    public function entry(string $raw, int $offset): LogEntry
    {
        $raw = rtrim($raw, "\r\n");
        preg_match(self::ENTRY_START, $raw, $head);

        $time = $head[1] ?? '';
        $body = trim(substr($raw, strlen($head[0] ?? '')));

        [$message, $context, $extra] = self::split($body);
        $exception = is_array($context) && is_string($context['exception'] ?? null) ? self::exception($context['exception']) : null;

        return new LogEntry(
            offset: $offset,
            time: $time,
            timestamp: $this->timestamp($time),
            channel: $head[2] ?? '',
            level: strtolower($head[3] ?? 'debug'),
            message: $message,
            context: $context,
            extra: $extra,
            exception: $exception,
            raw: $raw,
        );
    }

    /**
     * Laravel's exception string: "[object] (Class(code: 0): message at /file.php:12)", then
     * "[stacktrace]" and "#0 /file.php(12): call()" lines. Previous exceptions follow as
     * "[previous exception] [object] (...)" blocks (Monolog 2 and 3), or inside the same
     * parentheses after ", " (Monolog 1, Laravel 5.5 to 5.8).
     *
     * @return array{class: string, code: string, message: string, file: string, line: int, frames: list<array{file: string|null, line: int|null, call: string}>, previous: list<string>}|null
     */
    public static function exception(string $text): ?array
    {
        // One "Class(code: 0): message at /file.php:12", ending where the parentheses close or the next one starts.
        $one = '([^\s(),]+)\(code: ([^)]*)\): (.*?) at ((?:[A-Za-z]:)?[\\\\\/].*?|\S+?):(\d+)(?=\)\s*(?:\r?\n|$)|, [^\s(),]+\(code: )';

        if (preg_match('/^\[object\] \(' . $one . '/s', $text, $match) !== 1) {
            return null;
        }

        $rest = substr($text, strlen($match[0]));
        $previous = [];

        // Monolog 1: ", Previous(code: 0): message at /file.php:3" before the closing parenthesis.
        while (preg_match('/^, ' . $one . '/s', $rest, $inline) === 1) {
            $previous[] = $inline[1] . '(code: ' . $inline[2] . '): ' . $inline[3] . ' at ' . $inline[4] . ':' . $inline[5];
            $rest = substr($rest, strlen($inline[0]));
        }

        $frames = [];
        $inPrevious = false;

        foreach (preg_split('/\r?\n/', $rest) ?: [] as $line) {
            if (str_starts_with($line, '[previous exception] ')) {
                $previous[] = (string) preg_replace('/^\[object\] \((.*)\)$/', '$1', substr($line, strlen('[previous exception] ')));
                $inPrevious = true;
            } elseif (! $inPrevious && preg_match('/^#\d+ (?:(.+?)\((\d+)\): )?(.+)$/', $line, $frame) === 1) {
                $frames[] = [
                    'file' => $frame[1] !== '' ? $frame[1] : null,
                    'line' => $frame[2] !== '' ? (int) $frame[2] : null,
                    'call' => $frame[3],
                ];
            }
        }

        return [
            'class' => $match[1],
            'code' => $match[2],
            'message' => $match[3],
            'file' => $match[4],
            'line' => (int) $match[5],
            'frames' => $frames,
            'previous' => $previous,
        ];
    }

    /**
     * "%message% %context% %extra%": the message, then up to two JSON values at the end.
     *
     * @return array{0: string, 1: mixed, 2: mixed}
     */
    private static function split(string $body): array
    {
        $extra = null;

        if (str_ends_with($body, ' []')) {
            $body = substr($body, 0, -3);
        } else {
            [$body, $extra] = self::trailingJson($body);
        }

        [$message, $context] = self::trailingJson($body);

        // A formatter without %extra% leaves one JSON value at the end: that is the context.
        if ($context === null && $extra !== null) {
            [$context, $extra] = [$extra, null];
        }

        return [$message, $context === [] ? null : $context, $extra === [] ? null : $extra];
    }

    /**
     * The longest JSON object or array the text ends with.
     *
     * @return array{0: string, 1: mixed} The text before it, and the decoded value (null when none).
     */
    private static function trailingJson(string $text): array
    {
        $last = substr($text, -1);
        if (($last !== '}' && $last !== ']') || strlen($text) > self::MAX_JSON_BYTES) {
            return [$text, null];
        }

        $tries = 0;
        $position = -1;

        while (($position = self::nextOpening($text, $position + 1)) !== null && $tries < self::MAX_JSON_TRIES) {
            $tries++;

            // Monolog writes the JSON on one line; a line break inside it can only be inside a string.
            $candidate = str_replace(["\r", "\n", "\t"], ['\r', '\n', '\t'], substr($text, $position));
            $decoded = json_decode($candidate, true);

            if ($decoded !== null) {
                return [rtrim(substr($text, 0, $position)), $decoded];
            }
        }

        return [$text, null];
    }

    /**
     * The next "{" or "[" that can start the JSON: after a space, or at the very start.
     */
    private static function nextOpening(string $text, int $from): ?int
    {
        $length = strlen($text);

        while ($from < $length) {
            $position = strcspn($text, '{[', $from) + $from;
            if ($position >= $length) {
                return null;
            }

            if ($position === 0 || $text[$position - 1] === ' ') {
                return $position;
            }

            $from = $position + 1;
        }

        return null;
    }

    private function timestamp(string $time): ?float
    {
        if ($time === '') {
            return null;
        }

        try {
            return (float) new DateTimeImmutable($time, $this->timezone)->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }
}
