<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Switch\SwitchStore;

/**
 * GET /_peep/switches?project=: the switches a client should follow.
 */
final readonly class SwitchController implements RequestHandler
{
    public function __construct(
        private SwitchStore $switches,
    ) {}

    public function handleRequest(Request $request): Response
    {
        parse_str($request->getUri()->getQuery(), $query);
        $project = is_string($query['project'] ?? null) && $query['project'] !== '' ? $query['project'] : 'default';

        return Responses::json(200, $this->switches->answer($project));
    }
}
