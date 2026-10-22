<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Lock\LockRegistry;

/**
 * GET  /_peep/locks/{name}                 the paused app polls this.
 * POST /_peep/ui/locks/{name}/continue      the Continue button.
 * POST /_peep/ui/locks/{name}/stop          the Stop button.
 */
final readonly class LockController implements RequestHandler
{
    public function __construct(
        private LockRegistry $locks,
        private EventHub $hub,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'GET' && preg_match('#^/_peep/locks/([A-Za-z0-9_-]{1,64})$#', $path, $matches) === 1) {
            $state = $this->locks->state($matches[1], microtime(true));

            return $state === null
                ? Responses::error(404, 'unknown_lock', 'No such lock, or it expired.')
                : Responses::json(200, $state);
        }

        if ($request->getMethod() === 'POST' && preg_match('#^/_peep/ui/locks/([A-Za-z0-9_-]{1,64})/(continue|stop)$#', $path, $matches) === 1) {
            $stop = $matches[2] === 'stop';

            if (! $this->locks->release($matches[1], $stop)) {
                return Responses::error(404, 'unknown_lock', 'No such lock, or it was already released.');
            }

            $this->hub->publish([new ServerEvent('lock.released', ['name' => $matches[1], 'stop' => $stop])]);

            return Responses::json(200, ['released' => true, 'stop' => $stop]);
        }

        return Responses::error(404, 'not_found', 'Not found.');
    }
}
