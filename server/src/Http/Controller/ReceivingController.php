<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Card\Receiving;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;

/**
 * POST /_peep/ui/receiving {"paused": true}: pause or resume receiving.
 */
final readonly class ReceivingController implements RequestHandler
{
    public function __construct(
        private Receiving $receiving,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $paused = JsonBody::read($request)['paused'] ?? null;

        if (! is_bool($paused)) {
            return Responses::error(400, 'invalid_request', 'Send {"paused": true} or {"paused": false}.');
        }

        $this->receiving->paused = $paused;
        $this->hub->publish([new ServerEvent('receiving.changed', ['paused' => $paused])]);

        return Responses::json(200, ['paused' => $paused]);
    }
}
