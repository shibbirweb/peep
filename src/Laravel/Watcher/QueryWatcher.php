<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use DateTimeInterface;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Control\Switches;
use ShibbirWeb\Peep\Support\Uuid;
use Throwable;

/**
 * Database queries: every query (optionally only some types), slow queries, duplicate
 * queries (likely N+1), queries matching a condition, and query counts.
 *
 * Whether each kind is shown is decided per query, in this order:
 * 1. a runtime call such as peep()->showQueries() / stopShowingQueries();
 * 2. the viewer's switch ("on" or "off"), so it can be flipped while the app runs;
 * 3. the "peep" config.
 */
final class QueryWatcher extends AbstractWatcher
{
    public const TYPES = ['select', 'insert', 'update', 'delete', 'other'];

    private const EVENT = 'Illuminate\\Database\\Events\\QueryExecuted';

    /**
     * @var bool|null
     */
    private $slowOverride;

    /**
     * @var int|null
     */
    private $slowMsOverride;

    /**
     * @var bool|null
     */
    private $duplicateOverride;

    /**
     * @var list<string>|null Runtime filter from showSelectQueries() and friends.
     */
    private $typesOverride;

    /**
     * @var callable|null From showConditionalQueries().
     */
    private $condition;

    /**
     * @var array{count: int, time: float}|null While countQueries() runs.
     */
    private $counting;

    /**
     * @var array<string, array<string, array{count: int, card: string}>> request id => query hash => seen
     */
    private $seen = [];

    public function isSupported(): bool
    {
        return class_exists(self::EVENT);
    }

    public function register($app): void
    {
        $app->make('events')->listen(self::EVENT, function ($query) {
            $this->handle($query);
        });
    }

    /**
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    public function handle($query): void
    {
        $time = isset($query->time) ? (float) $query->time : 0.0;

        if ($this->counting !== null) {
            $this->counting['count']++;
            $this->counting['time'] += $time;
        }

        if (! $this->factory->dispatcher()->isActive()) {
            return;
        }

        try {
            $this->show($query, $time);
        } catch (Throwable $exception) {
            // A query must never fail because of Peep.
        }
    }

    public function isOn(): bool
    {
        return $this->decide('queries', $this->override(), 'send_queries');
    }

    public function showSlow(bool $show, ?int $thresholdMs = null): void
    {
        $this->slowOverride = $show;
        if ($thresholdMs !== null) {
            $this->slowMsOverride = $thresholdMs;
        }
    }

    public function showDuplicates(bool $show): void
    {
        $this->duplicateOverride = $show;
    }

    /**
     * @param list<string>|null $types Null shows every type.
     */
    public function onlyTypes(?array $types): void
    {
        $this->typesOverride = $types;
    }

    public function showWhen(?callable $condition): void
    {
        $this->condition = $condition;
    }

    /**
     * Run a callback while slow (or duplicate) queries are shown, then restore.
     *
     * @return mixed
     */
    public function duringSlow(callable $callback, ?int $thresholdMs = null)
    {
        $previous = [$this->slowOverride, $this->slowMsOverride];
        $this->showSlow(true, $thresholdMs);

        try {
            return $callback();
        } finally {
            list($this->slowOverride, $this->slowMsOverride) = $previous;
        }
    }

    /**
     * @return mixed
     */
    public function duringDuplicates(callable $callback)
    {
        $previous = $this->duplicateOverride;
        $this->duplicateOverride = true;

        try {
            return $callback();
        } finally {
            $this->duplicateOverride = $previous;
        }
    }

    /**
     * @param list<string> $types
     * @return mixed
     */
    public function duringTypes(array $types, callable $callback)
    {
        $previous = $this->typesOverride;
        $this->typesOverride = $types;

        try {
            return $this->during($callback);
        } finally {
            $this->typesOverride = $previous;
        }
    }

    /**
     * Count the queries a callback runs and their total time.
     *
     * @return array{result: mixed, count: int, time: float}
     */
    public function count(callable $callback): array
    {
        $previous = $this->counting;
        $this->counting = ['count' => 0, 'time' => 0.0];

        try {
            $result = $callback();
            $counted = $this->counting;
        } finally {
            $this->counting = $previous;
        }

        return ['result' => $result, 'count' => $counted['count'], 'time' => $counted['time']];
    }

    protected function configKey(): string
    {
        return 'send_queries';
    }

    /**
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    private function show($query, float $time): void
    {
        $sql = (string) $query->sql;
        $type = self::typeOf($sql);

        $all = $this->isOn() && in_array($type, $this->types(), true);
        $slow = $this->decide('slow_queries', $this->slowOverride, 'send_slow_queries') && $time >= $this->slowThreshold();
        $matches = $this->condition !== null && (bool) call_user_func($this->condition, $query);
        $duplicate = $this->decide('duplicate_queries', $this->duplicateOverride, 'send_duplicate_queries')
            ? $this->recordRepeat($query, $sql)
            : null;

        if (! $all && ! $slow && ! $matches && $duplicate === null) {
            return;
        }

        $content = [
            'sql' => $sql,
            'bindings' => self::bindings((array) $query->bindings),
            'time_ms' => round($time, 2),
            'connection' => (string) $query->connectionName,
            'driver' => self::driver($query),
            'type' => $type,
            'slow' => $slow,
            'duplicate_count' => $duplicate !== null ? $duplicate['count'] : null,
        ];

        // Repeats of one query update the same card, so the count grows in place.
        $peep = $this->peep($duplicate !== null ? $duplicate['card'] : null)->payload('query', $content);

        if ($slow) {
            $peep->orange();
        } elseif ($duplicate !== null) {
            $peep->yellow();
        }
    }

    /**
     * @param string $switch Viewer switch name.
     */
    private function decide(string $switch, ?bool $override, string $configKey): bool
    {
        if (! $this->factory->dispatcher()->isActive()) {
            return false;
        }

        if ($override !== null) {
            return $override;
        }

        $state = $this->factory->dispatcher()->switches()->state($switch);
        if ($state !== Switches::DEFAULT_STATE) {
            return $state === Switches::ON;
        }

        return Config::toBool(isset($this->config[$configKey]) ? $this->config[$configKey] : false, false);
    }

    /**
     * Counts a query within the current request. Returns the count and card from the
     * second time on (the first run of a query is not a duplicate).
     *
     * @param \Illuminate\Database\Events\QueryExecuted $query
     * @return array{count: int, card: string}|null
     */
    private function recordRepeat($query, string $sql): ?array
    {
        $request = $this->factory->context()->current()['id'];
        $bindings = self::bindings((array) $query->bindings);
        $connection = (string) $query->connectionName;
        $hash = md5($connection . "\n" . $sql . "\n" . (string) json_encode($bindings));

        // Forget other requests (long-running workers handle many).
        $this->seen = array_intersect_key($this->seen, [$request => true]);

        if (! isset($this->seen[$request][$hash])) {
            $this->seen[$request][$hash] = ['count' => 1, 'card' => Uuid::v4()];

            return null;
        }

        $this->seen[$request][$hash]['count']++;

        return $this->seen[$request][$hash];
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        if ($this->typesOverride !== null) {
            return $this->typesOverride;
        }

        $types = isset($this->config['query_types']) && is_array($this->config['query_types']) ? $this->config['query_types'] : self::TYPES;

        return array_values(array_filter($types, 'is_string'));
    }

    private function slowThreshold(): float
    {
        if ($this->slowMsOverride !== null) {
            return (float) $this->slowMsOverride;
        }

        $fromViewer = $this->factory->dispatcher()->switches()->intValue('slow_query_ms');
        if ($fromViewer !== null) {
            return (float) $fromViewer;
        }

        return isset($this->config['slow_query_ms']) && is_numeric($this->config['slow_query_ms']) ? (float) $this->config['slow_query_ms'] : 500.0;
    }

    private static function typeOf(string $sql): string
    {
        if (preg_match('/^\s*\(?\s*(select|insert|update|delete)\b/i', $sql, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return 'other';
    }

    /**
     * @param array<mixed> $bindings
     * @return list<mixed>
     */
    private static function bindings(array $bindings): array
    {
        $normalized = [];

        foreach ($bindings as $binding) {
            if ($binding instanceof DateTimeInterface) {
                $binding = $binding->format('Y-m-d H:i:s');
            } elseif (is_bool($binding)) {
                $binding = $binding ? 1 : 0;
            } elseif (is_object($binding)) {
                $binding = method_exists($binding, '__toString') ? (string) $binding : get_class($binding);
            } elseif (is_string($binding) && preg_match('//u', $binding) !== 1) {
                $binding = '0x' . bin2hex(substr($binding, 0, 64));
            }

            $normalized[] = $binding;
        }

        return $normalized;
    }

    /**
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    private static function driver($query): ?string
    {
        try {
            return method_exists($query->connection, 'getDriverName') ? (string) $query->connection->getDriverName() : null;
        } catch (Throwable $exception) {
            return null;
        }
    }
}
