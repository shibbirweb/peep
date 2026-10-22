<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Http;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Http\Guard\TokenGuard;

final class TokenGuardTest extends TestCase
{
    private const string TOKEN = 'a-long-shared-token-123';

    public function testThisMachineNeedsNoToken(): void
    {
        $guard = new TokenGuard(self::TOKEN);

        foreach (['127.0.0.1', '127.0.0.5', '::1', '::ffff:127.0.0.1'] as $ip) {
            $this->assertSame(200, $this->statusOf($guard, $ip, '/_peep/stream'), $ip);
        }
    }

    public function testOtherMachinesNeedTheTokenAndOnlyReachTheClientApi(): void
    {
        $guard = new TokenGuard(self::TOKEN);

        $this->assertSame(200, $this->statusOf($guard, '172.17.0.2', '/_peep/envelope', self::TOKEN));
        $this->assertSame(200, $this->statusOf($guard, '172.17.0.2', '/_peep/locks/abc', self::TOKEN));
        $this->assertSame(401, $this->statusOf($guard, '172.17.0.2', '/_peep/envelope'));
        $this->assertSame(401, $this->statusOf($guard, '172.17.0.2', '/_peep/envelope', 'wrong-token-wrong-token'));
        $this->assertSame(403, $this->statusOf($guard, '172.17.0.2', '/_peep/stream', self::TOKEN), 'The UI stays on this machine.');
        $this->assertSame(403, $this->statusOf($guard, '172.17.0.2', '/', self::TOKEN));
    }

    public function testWithoutATokenOtherMachinesAreRefused(): void
    {
        $this->assertSame(403, $this->statusOf(new TokenGuard(null), '192.168.1.20', '/_peep/envelope'));
    }

    private function statusOf(TokenGuard $guard, string $ip, string $path, ?string $token = null): int
    {
        $client = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress($ip, 50000));

        $request = new Request($client, 'POST', Http::new('http://127.0.0.1:23517' . $path), $token !== null ? ['x-peep-token' => $token] : []);

        return $guard->handleRequest($request, new ClosureRequestHandler(static fn(): Response => new Response(200)))->getStatus();
    }
}
