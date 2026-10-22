<?php

namespace ShibbirWeb\Peep\Context;

use ShibbirWeb\Peep\Support\Uuid;

/**
 * The HTTP request, CLI command or queued job that cards belong to.
 *
 * The first context is detected from the environment. Adapters begin() a new one
 * when a job or command starts inside a long-running process and end() it after,
 * so the viewer can group cards per request.
 */
final class ExecutionContext
{
    /**
     * @var list<array{id: string, kind: string, label: string, started_at: float}>
     */
    private $stack = [];

    /**
     * @return array{id: string, kind: string, label: string, started_at: float}
     */
    public function current(): array
    {
        if ($this->stack === []) {
            $this->stack[] = self::detect();
        }

        return $this->stack[count($this->stack) - 1];
    }

    public function begin(string $kind, string $label): void
    {
        $this->current();

        $this->stack[] = [
            'id' => Uuid::v4(),
            'kind' => $kind,
            'label' => $label,
            'started_at' => microtime(true),
        ];
    }

    public function end(): void
    {
        if (count($this->stack) > 1) {
            array_pop($this->stack);
        }
    }

    /**
     * Forget everything; the next current() detects a new context. Used by
     * long-running servers between requests.
     */
    public function reset(): void
    {
        $this->stack = [];
    }

    /**
     * @return array{id: string, kind: string, label: string, started_at: float}
     */
    private static function detect(): array
    {
        $startedAt = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_float($_SERVER['REQUEST_TIME_FLOAT'])
            ? $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return [
                'id' => Uuid::v4(),
                'kind' => 'cli',
                'label' => self::commandLine(),
                'started_at' => $startedAt,
            ];
        }

        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        return [
            'id' => Uuid::v4(),
            'kind' => 'http',
            'label' => $method . ' ' . $uri,
            'started_at' => $startedAt,
        ];
    }

    private static function commandLine(): string
    {
        if (! isset($_SERVER['argv']) || ! is_array($_SERVER['argv']) || $_SERVER['argv'] === []) {
            return 'php';
        }

        $arguments = [];
        foreach ($_SERVER['argv'] as $index => $argument) {
            if (! is_string($argument)) {
                continue;
            }

            $arguments[] = $index === 0 ? basename($argument) : $argument;
        }

        return implode(' ', $arguments);
    }
}
