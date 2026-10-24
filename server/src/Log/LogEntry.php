<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Log;

/**
 * One log entry, as LogParser read it.
 */
final readonly class LogEntry
{
    /**
     * @param int $offset Byte offset in the file where the entry starts.
     * @param string $time As written in the file.
     * @param float|null $timestamp Unix time, read in the app's time zone when the file has no offset.
     * @param string $level Lower case: debug, info, notice, warning, error, critical, alert, emergency.
     * @param mixed $context The decoded context JSON, or null.
     * @param mixed $extra The decoded extra JSON, or null.
     * @param array{class: string, code: string, message: string, file: string, line: int, frames: list<array{file: string|null, line: int|null, call: string}>, previous: list<string>}|null $exception
     */
    public function __construct(
        public int $offset,
        public string $time,
        public ?float $timestamp,
        public string $channel,
        public string $level,
        public string $message,
        public mixed $context,
        public mixed $extra,
        public ?array $exception,
        public string $raw,
    ) {}

    /**
     * At or above $level (error: error, critical, alert and emergency).
     */
    public function isAtLeast(string $level): bool
    {
        $wanted = array_search($level, LogParser::LEVELS, true);
        $own = array_search($this->level, LogParser::LEVELS, true);

        return $wanted === false || ($own !== false && $own >= $wanted);
    }

    /**
     * @return array{offset: int, time: string, timestamp: float|null, channel: string, level: string, message: string, context: mixed, extra: mixed, exception: array<string, mixed>|null, raw: string}
     */
    public function toArray(): array
    {
        return [
            'offset' => $this->offset,
            'time' => $this->time,
            'timestamp' => $this->timestamp,
            'channel' => $this->channel,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'extra' => $this->extra,
            'exception' => $this->exception,
            'raw' => $this->raw,
        ];
    }
}
