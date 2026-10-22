<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Http\Guard\HostGuard;
use ShibbirWeb\PeepServer\Http\Guard\OriginGuard;
use ShibbirWeb\PeepServer\Tests\Support\Requests;

final class GuardTest extends TestCase
{
    use Requests;

    public function testHostGuardAcceptsOnlyTheViewersOwnAddress(): void
    {
        $guard = new HostGuard('127.0.0.1', 23517);

        foreach (['127.0.0.1:23517', 'localhost:23517', '[::1]:23517', 'LOCALHOST:23517'] as $host) {
            $this->assertSame(200, $this->statusOf($guard, $this->request('GET', '/', ['host' => $host])), $host);
        }

        foreach (['evil.example:23517', 'localhost', '127.0.0.1:80', '127.0.0.1.evil.example:23517', ''] as $host) {
            $this->assertSame(421, $this->statusOf($guard, $this->request('GET', '/', ['host' => $host])), $host);
        }
    }

    public function testOriginGuardAllowsRequestsWithoutOriginAndFromTheViewerItself(): void
    {
        $guard = new OriginGuard('127.0.0.1', 23517);

        $this->assertSame(200, $this->statusOf($guard, $this->request('POST', '/_peep/envelope')));
        $this->assertSame(200, $this->statusOf($guard, $this->request('POST', '/_peep/envelope', ['origin' => 'http://127.0.0.1:23517'])));
        $this->assertSame(200, $this->statusOf($guard, $this->request('POST', '/_peep/envelope', ['origin' => 'http://localhost:23517/'])));
        $this->assertSame(200, $this->statusOf($guard, $this->request('POST', '/_peep/ui/clear', ['origin' => 'tauri://localhost', 'x-peep-ui' => '1'])));
    }

    public function testOriginGuardBlocksOtherWebPages(): void
    {
        $guard = new OriginGuard('127.0.0.1', 23517);

        foreach (['https://evil.example', 'http://127.0.0.1:9999', 'null'] as $origin) {
            $this->assertSame(403, $this->statusOf($guard, $this->request('POST', '/_peep/envelope', ['origin' => $origin])), $origin);
        }

        $this->assertSame(403, $this->statusOf($guard, $this->request('OPTIONS', '/_peep/ui/clear', ['origin' => 'http://127.0.0.1:23517'])));
    }

    public function testUiActionsNeedTheUiHeader(): void
    {
        $guard = new OriginGuard('127.0.0.1', 23517);

        $this->assertSame(403, $this->statusOf($guard, $this->request('POST', '/_peep/ui/clear')));
        $this->assertSame(200, $this->statusOf($guard, $this->request('POST', '/_peep/ui/clear', ['x-peep-ui' => '1'])));
        $this->assertSame(200, $this->statusOf($guard, $this->request('GET', '/_peep/ui/settings')));
    }

    private function statusOf(HostGuard|OriginGuard $guard, Request $request): int
    {
        return $guard->handleRequest($request, new ClosureRequestHandler(static fn(): Response => new Response(200)))->getStatus();
    }
}
