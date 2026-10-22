<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Http;

use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Http\Controller\StaticFileController;
use ShibbirWeb\PeepServer\Http\Router;
use ShibbirWeb\PeepServer\Tests\Support\Requests;

final class RouterAndStaticFileTest extends TestCase
{
    use Requests;

    private string $publicDirectory;

    protected function setUp(): void
    {
        $this->publicDirectory = sys_get_temp_dir() . '/peep-public-' . bin2hex(random_bytes(4));
        mkdir($this->publicDirectory . '/assets', 0777, true);
        file_put_contents($this->publicDirectory . '/index.html', '<!doctype html><title>Peep</title>');
        file_put_contents($this->publicDirectory . '/assets/app.js', 'console.log(1);');
        file_put_contents(dirname($this->publicDirectory) . '/peep-secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        unlink($this->publicDirectory . '/assets/app.js');
        unlink($this->publicDirectory . '/index.html');
        rmdir($this->publicDirectory . '/assets');
        rmdir($this->publicDirectory);
        unlink(dirname($this->publicDirectory) . '/peep-secret.txt');
    }

    public function testRoutesByMethodAndPath(): void
    {
        $router = new Router()
            ->add('GET', '/_peep/health', new ClosureRequestHandler(static fn(): Response => new Response(200, [], 'health')))
            ->add('POST', '/_peep/envelope', new ClosureRequestHandler(static fn(): Response => new Response(202)))
            ->add('GET', '/*', new ClosureRequestHandler(static fn(): Response => new Response(200, [], 'static')));

        $this->assertSame(200, $router->handleRequest($this->request('GET', '/_peep/health'))->getStatus());
        $this->assertSame(200, $router->handleRequest($this->request('HEAD', '/_peep/health'))->getStatus());
        $this->assertSame(202, $router->handleRequest($this->request('POST', '/_peep/envelope'))->getStatus());

        $wrongMethod = $router->handleRequest($this->request('GET', '/_peep/envelope'));
        $this->assertSame(200, $wrongMethod->getStatus(), 'GET falls through to the static prefix route.');

        $notAllowed = $router->handleRequest($this->request('DELETE', '/_peep/envelope'));
        $this->assertSame(405, $notAllowed->getStatus());
        $this->assertSame('POST, GET', $notAllowed->getHeader('allow'));
    }

    public function testStaticFilesAreServedWithSecurityHeaders(): void
    {
        $controller = new StaticFileController($this->publicDirectory);

        $index = $controller->handleRequest($this->request('GET', '/'));
        $this->assertSame(200, $index->getStatus());
        $this->assertSame('text/html; charset=utf-8', $index->getHeader('content-type'));
        $this->assertStringContainsString("default-src 'self'", (string) $index->getHeader('content-security-policy'));
        $this->assertSame('no-cache', $index->getHeader('cache-control'));

        $asset = $controller->handleRequest($this->request('GET', '/assets/app.js'));
        $this->assertSame(200, $asset->getStatus());
        $this->assertStringContainsString('immutable', (string) $asset->getHeader('cache-control'));
    }

    public function testStaticFilesCannotEscapeThePublicDirectory(): void
    {
        $controller = new StaticFileController($this->publicDirectory);

        foreach (['/../peep-secret.txt', '/%2e%2e/peep-secret.txt', '/assets/../../peep-secret.txt', '/.hidden', '/missing.js'] as $path) {
            $this->assertSame(404, $controller->handleRequest($this->request('GET', $path))->getStatus(), $path);
        }
    }

    public function testMissingUiBuildIsExplained(): void
    {
        $response = new StaticFileController($this->publicDirectory . '/assets')->handleRequest($this->request('GET', '/'));

        $this->assertSame(503, $response->getStatus());
    }
}
