<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Support;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use PHPUnit\Framework\TestCase;

/**
 * Builds amphp Request objects for handler and middleware tests.
 */
trait Requests
{
    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): Request
    {
        assert($this instanceof TestCase);

        return new Request(
            $this->createStub(Client::class),
            $method,
            Http::new('http://127.0.0.1:23517' . $path),
            ['host' => '127.0.0.1:23517', ...$headers],
            $body,
        );
    }
}
