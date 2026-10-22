<?php

namespace ShibbirWeb\Peep\Laravel\Macro;

use Closure;

/**
 * peep() at the end of Laravel chains, returning the chain unchanged:
 *
 *     collect($users)->peep()->map(...);
 *     Str::of('x')->append('y')->peep();
 *     User::query()->where('active', 1)->peep()->get();
 *     $this->get('/')->peep()->assertOk();
 */
final class FrameworkMacros
{
    private function __construct()
    {
    }

    public static function register(): void
    {
        self::collection(function (?string $label = null) {
            $peep = peep($this);
            if ($label !== null) {
                $peep->label($label);
            }

            return $this;
        });

        // Stringable exists from Laravel 7.
        self::stringable(function (?string $label = null) {
            $peep = peep((string) $this);
            if ($label !== null) {
                $peep->label($label);
            }

            return $this;
        });

        // Eloquent builders forward unknown calls to their query builder, so this covers both.
        self::queryBuilder(function () {
            $connection = $this->getConnection();

            peep()->payload('query', [
                'sql' => $this->toSql(),
                'bindings' => $this->getBindings(),
                'time_ms' => null,
                'connection' => method_exists($connection, 'getName') ? $connection->getName() : null,
                'driver' => method_exists($connection, 'getDriverName') ? $connection->getDriverName() : null,
                'type' => 'select',
                'slow' => false,
                'duplicate_count' => null,
            ]);

            return $this;
        });

        self::testResponse(function () {
            $response = $this->baseResponse;
            $content = (string) $response->getContent();
            $json = json_decode($content, true);

            peep([
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
                'content' => $json !== null ? $json : $content,
            ])->label('Test response');

            return $this;
        });
    }

    /**
     * @param-closure-this \Illuminate\Support\Collection<array-key, mixed> $macro
     */
    private static function collection(Closure $macro): void
    {
        self::macro('Illuminate\\Support\\Collection', $macro);
    }

    /**
     * @param-closure-this \Illuminate\Support\Stringable $macro
     */
    private static function stringable(Closure $macro): void
    {
        self::macro('Illuminate\\Support\\Stringable', $macro);
    }

    /**
     * @param-closure-this \Illuminate\Database\Query\Builder $macro
     */
    private static function queryBuilder(Closure $macro): void
    {
        self::macro('Illuminate\\Database\\Query\\Builder', $macro);
    }

    /**
     * TestResponse moved from Illuminate\Foundation\Testing (Laravel 5.4 to 6) to Illuminate\Testing (7+).
     *
     * @param-closure-this \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $macro
     */
    private static function testResponse(Closure $macro): void
    {
        self::macro('Illuminate\\Testing\\TestResponse', $macro);
        self::macro('Illuminate\\Foundation\\Testing\\TestResponse', $macro);
    }

    private static function macro(string $class, Closure $macro): void
    {
        if (class_exists($class) && method_exists($class, 'macro')) {
            $class::macro('peep', $macro);
        }
    }
}
