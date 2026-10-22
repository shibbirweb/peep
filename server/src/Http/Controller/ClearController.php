<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;

/**
 * POST /_peep/ui/clear: clears one project's cards, or all with no project given.
 */
final readonly class ClearController implements RequestHandler
{
    public function __construct(
        private CardStore $cards,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $project = JsonBody::project(JsonBody::read($request));

        $this->hub->publish($this->cards->clear($project));

        return Responses::json(200, ['cleared' => true, 'project' => $project]);
    }
}
