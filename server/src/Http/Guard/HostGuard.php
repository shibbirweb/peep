<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Guard;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Http\Responses;

/**
 * Rejects requests whose Host header is not the viewer itself.
 *
 * A web page can make the browser resolve its own domain to 127.0.0.1 (DNS rebinding)
 * and then talk to the viewer as a "same origin" page. Such requests still carry the
 * attacker's domain in Host, so checking Host stops them.
 */
final readonly class HostGuard implements Middleware
{
    /**
     * @var list<string>
     */
    private array $allowedHosts;

    public function __construct(string $bindHost, private int $port)
    {
        $this->allowedHosts = array_values(array_unique(['127.0.0.1', 'localhost', '[::1]', strtolower($bindHost)]));
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $host = strtolower(trim((string) $request->getHeader('host')));

        if (! $this->isAllowed($host)) {
            return Responses::error(421, 'invalid_host', 'This server only answers to its own address.');
        }

        return $requestHandler->handleRequest($request);
    }

    public function isAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            if ($host === $allowed . ':' . $this->port || ($this->port === 80 && $host === $allowed)) {
                return true;
            }
        }

        return false;
    }
}
