<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Guard;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Http\Responses;

/**
 * Stops other web pages from using the viewer through the user's browser.
 *
 * - Browsers send an Origin header with cross-site requests; only the viewer's own
 *   origins (and the desktop app's) are accepted. PHP clients send no Origin.
 * - UI actions (/_peep/ui/*) also need the X-Peep-UI header. A custom header makes the
 *   browser send a CORS preflight first, which the viewer never approves for other sites.
 */
final readonly class OriginGuard implements Middleware
{
    public const string UI_HEADER = 'x-peep-ui';

    private const array DESKTOP_ORIGINS = ['tauri://localhost', 'http://tauri.localhost', 'https://tauri.localhost'];

    /**
     * @var list<string>
     */
    private array $allowedOrigins;

    public function __construct(string $bindHost, int $port)
    {
        $hosts = array_unique(['127.0.0.1', 'localhost', '[::1]', strtolower($bindHost)]);

        $this->allowedOrigins = [
            ...array_map(static fn(string $host): string => 'http://' . $host . ':' . $port, $hosts),
            ...self::DESKTOP_ORIGINS,
        ];
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $origin = $request->getHeader('origin');

        if ($origin !== null && ! in_array(strtolower(rtrim($origin, '/')), $this->allowedOrigins, true)) {
            return Responses::error(403, 'forbidden_origin', 'Requests from other web pages are not allowed.');
        }

        if ($request->getMethod() === 'OPTIONS') {
            return Responses::error(403, 'forbidden_origin', 'Cross-origin requests are not allowed.');
        }

        $isUiAction = str_starts_with($request->getUri()->getPath(), '/_peep/ui/') && $request->getMethod() !== 'GET';
        if ($isUiAction && $request->getHeader(self::UI_HEADER) !== '1') {
            return Responses::error(403, 'missing_ui_header', 'UI actions must come from the Peep UI.');
        }

        return $requestHandler->handleRequest($request);
    }
}
