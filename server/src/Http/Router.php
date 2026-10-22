<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Maps method and path to a handler. Paths are exact, or end in "/*" for a prefix.
 */
final class Router implements RequestHandler
{
    /**
     * @var list<array{method: string, path: string, handler: RequestHandler}>
     */
    private array $routes = [];

    public function add(string $method, string $path, RequestHandler $handler): self
    {
        $this->routes[] = ['method' => strtoupper($method), 'path' => $path, 'handler' => $handler];

        return $this;
    }

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod() === 'HEAD' ? 'GET' : $request->getMethod();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (! self::matches($route['path'], $path)) {
                continue;
            }

            if ($route['method'] === $method) {
                return $route['handler']->handleRequest($request);
            }

            $allowed[] = $route['method'];
        }

        if ($allowed !== []) {
            $response = Responses::error(405, 'method_not_allowed', 'Method not allowed.');
            $response->setHeader('allow', implode(', ', array_unique($allowed)));

            return $response;
        }

        return Responses::error(404, 'not_found', 'Not found.');
    }

    private static function matches(string $pattern, string $path): bool
    {
        if (str_ends_with($pattern, '/*')) {
            return str_starts_with($path, substr($pattern, 0, -1));
        }

        return $pattern === $path;
    }
}
