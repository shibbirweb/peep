<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Version;

/**
 * GET /_peep/health: tells clients and tools that a Peep viewer listens here.
 */
final class HealthController implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return Responses::json(200, [
            'app' => 'peep',
            'protocol' => EnvelopeDecoder::PROTOCOL,
            'version' => Version::VERSION,
        ]);
    }
}
