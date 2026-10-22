<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\JsonBody;
use ShibbirWeb\PeepServer\Http\Responses;

/**
 * POST /_peep/ui/screens {"project": "shop"}  the New screen button: archives the cards.
 * GET  /_peep/ui/screens/{id}                  an archived screen with its cards.
 */
final readonly class ScreenController implements RequestHandler
{
    public function __construct(
        private CardStore $cards,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() === 'POST') {
            $project = JsonBody::project(JsonBody::read($request));

            if ($project === null) {
                return Responses::error(400, 'invalid_request', 'Send {"project": "..."}.');
            }

            $this->hub->publish($this->cards->newScreen($project, '', microtime(true)));

            return Responses::json(200, ['project' => $project]);
        }

        $id = substr($request->getUri()->getPath(), strlen('/_peep/ui/screens/'));
        $screen = $this->cards->screen($id);

        if ($screen === null) {
            return Responses::error(404, 'unknown_screen', 'No such screen; only the last ' . CardStore::MAX_SCREENS . ' are kept.');
        }

        return Responses::json(200, [
            ...$screen,
            'cards' => array_map(static fn(Card $card): array => $card->toArray(), $screen['cards']),
        ]);
    }
}
