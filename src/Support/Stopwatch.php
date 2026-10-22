<?php

namespace ShibbirWeb\Peep\Support;

/**
 * State behind measure(): the first call starts the stopwatch, every later call
 * reports the time since the start and since the previous call.
 */
final class Stopwatch
{
    /**
     * @var int|null
     */
    private $startedAt;

    /**
     * @var int
     */
    private $lastAt = 0;

    /**
     * @return array{started: bool, total_ms: float, since_last_ms: float, memory: int, peak_memory: int}
     */
    public function lap(): array
    {
        $now = Clock::nanoseconds();

        if ($this->startedAt === null) {
            $this->startedAt = $now;
            $this->lastAt = $now;

            return self::result(true, 0.0, 0.0);
        }

        $result = self::result(false, ($now - $this->startedAt) / 1e6, ($now - $this->lastAt) / 1e6);
        $this->lastAt = $now;

        return $result;
    }

    public function reset(): void
    {
        $this->startedAt = null;
        $this->lastAt = 0;
    }

    /**
     * @return array{started: bool, total_ms: float, since_last_ms: float, memory: int, peak_memory: int}
     */
    private static function result(bool $started, float $totalMs, float $sinceLastMs): array
    {
        return [
            'started' => $started,
            'total_ms' => round($totalMs, 3),
            'since_last_ms' => round($sinceLastMs, 3),
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }
}
