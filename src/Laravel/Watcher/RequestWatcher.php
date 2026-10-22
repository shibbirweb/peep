<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use Throwable;

/**
 * Incoming HTTP requests, after they were handled: route, controller, middleware,
 * status, duration and memory.
 */
final class RequestWatcher extends AbstractWatcher
{
    private const EVENT = 'Illuminate\\Foundation\\Http\\Events\\RequestHandled';

    public function isSupported(): bool
    {
        return class_exists(self::EVENT);
    }

    public function register($app): void
    {
        $app->make('events')->listen(self::EVENT, function ($event) {
            $this->handle($event->request, $event->response);
        });
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     */
    public function handle($request, $response): void
    {
        if (! $this->isOn()) {
            return;
        }

        try {
            $route = method_exists($request, 'route') ? $request->route() : null;
            $start = defined('LARAVEL_START') ? (float) constant('LARAVEL_START') : null;
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;

            $peep = $this->peep()->payload('request', [
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'route' => is_object($route) && method_exists($route, 'getName') ? $route->getName() : null,
                'action' => is_object($route) && method_exists($route, 'getActionName') ? $route->getActionName() : null,
                'middleware' => is_object($route) && method_exists($route, 'gatherMiddleware') ? array_values(array_map('strval', $route->gatherMiddleware())) : [],
                'status' => $status,
                'time_ms' => $start !== null ? round((microtime(true) - $start) * 1000, 2) : null,
                'peak_memory' => memory_get_peak_usage(true),
                'ip' => $request->ip(),
                'headers' => self::redact(self::headers($request)),
                'input' => $this->serialize(self::redact($request->all())),
            ]);

            if (is_int($status) && $status >= 500) {
                $peep->red();
            } elseif (is_int($status) && $status >= 400) {
                $peep->orange();
            }
        } catch (Throwable $exception) {
            // Never let Peep break a response.
        }
    }

    protected function configKey(): string
    {
        return 'send_requests';
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, string>
     */
    private static function headers($request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
        }

        return $headers;
    }
}
