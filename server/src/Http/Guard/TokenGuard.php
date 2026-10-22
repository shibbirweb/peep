<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Guard;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use ShibbirWeb\PeepServer\Http\ClientApi;
use ShibbirWeb\PeepServer\Http\Responses;

/**
 * Requests from other machines (or from Docker on Linux, through the bridge network)
 * may only use the client API, and only with the shared token in X-Peep-Token.
 * Requests from this machine need no token.
 */
final readonly class TokenGuard implements Middleware
{
    public const string HEADER = 'x-peep-token';

    public function __construct(
        private ?string $token,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $address = $request->getClient()->getRemoteAddress();

        if (! $address instanceof InternetAddress || self::isLoopback($address->getAddress())) {
            return $requestHandler->handleRequest($request);
        }

        if ($this->token === null) {
            return Responses::error(403, 'remote_disabled', 'Set a token in the Peep settings to accept apps from other machines.');
        }

        if (! ClientApi::isClientPath($request->getUri()->getPath())) {
            return Responses::error(403, 'local_only', 'The Peep viewer is only available on the machine it runs on.');
        }

        $sent = $request->getHeader(self::HEADER);
        if ($sent === null || ! hash_equals($this->token, $sent)) {
            return Responses::error(401, 'invalid_token', 'Send the token from the Peep settings as X-Peep-Token (PEEP_TOKEN in the app).');
        }

        return $requestHandler->handleRequest($request);
    }

    public static function isLoopback(string $ip): bool
    {
        $ip = strtolower($ip);

        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return $ip === '::1' || str_starts_with($ip, '127.');
    }
}
