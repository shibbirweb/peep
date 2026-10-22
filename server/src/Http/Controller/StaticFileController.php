<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Serves the built UI from public/ (also when running from the PHAR).
 */
final readonly class StaticFileController implements RequestHandler
{
    private const array CONTENT_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'json' => 'application/json',
        'woff2' => 'font/woff2',
    ];

    /**
     * The page may load only its own files; HTML sent by apps is shown in sandboxed frames.
     */
    private const string CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http:; frame-src 'self' blob: data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'self' tauri://localhost http://tauri.localhost https://tauri.localhost";

    public function __construct(
        private string $publicDirectory,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $path = rawurldecode($request->getUri()->getPath());

        if ($path === '/' || $path === '') {
            $path = '/index.html';
        }

        // Only plain relative paths inside public/: no "..", no hidden files.
        if (preg_match('#^(/[A-Za-z0-9_\-][A-Za-z0-9_.\-]*)+$#', $path) !== 1 || str_contains($path, '..')) {
            return $this->notFound();
        }

        $file = $this->publicDirectory . $path;
        if (! is_file($file)) {
            return $path === '/index.html' ? $this->uiNotBuilt() : $this->notFound();
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $isAsset = str_starts_with($path, '/assets/');

        return new Response(200, [
            'content-type' => self::CONTENT_TYPES[$extension] ?? 'application/octet-stream',
            'cache-control' => $isAsset ? 'public, max-age=31536000, immutable' : 'no-cache',
            'content-security-policy' => self::CONTENT_SECURITY_POLICY,
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer',
        ], (string) file_get_contents($file));
    }

    private function notFound(): Response
    {
        return new Response(404, ['content-type' => 'text/plain; charset=utf-8'], 'Not found.');
    }

    private function uiNotBuilt(): Response
    {
        return new Response(503, ['content-type' => 'text/plain; charset=utf-8'], "The Peep UI is not built yet.\nRun: cd server/resources/ui && bun install && bun run build\n");
    }
}
