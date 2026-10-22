<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Card\Receiving;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Lock\LockRegistry;
use ShibbirWeb\PeepServer\Settings\SettingsStore;
use ShibbirWeb\PeepServer\Switch\SwitchStore;

/**
 * GET /_peep/stream: Server-Sent Events for the UI.
 *
 * A new connection gets a "snapshot" event with the current cards. A reconnecting
 * browser sends Last-Event-ID and gets only what it missed, or a fresh snapshot when
 * that is no longer in the hub's history.
 */
final readonly class StreamController implements RequestHandler
{
    public function __construct(
        private CardStore $cards,
        private EventHub $hub,
        private LockRegistry $locks,
        private SwitchStore $switches = new SwitchStore(),
        private Receiving $receiving = new Receiving(),
        private SettingsStore $settings = new SettingsStore(),
    ) {}

    public function handleRequest(Request $request): Response
    {
        [$subscriberId, $queue] = $this->hub->subscribe();

        $lastEventId = $request->getHeader('last-event-id');
        $missed = $lastEventId !== null && ctype_digit($lastEventId) ? $this->hub->since((int) $lastEventId) : null;

        if ($missed === null) {
            $queue->pushAsync($this->snapshot()->toSse())->ignore();
        } else {
            foreach ($missed as $event) {
                $queue->pushAsync($event->toSse())->ignore();
            }
        }

        $stream = new ReadableIterableStream(self::chunks($queue->pipe()));
        $stream->onClose(fn() => $this->hub->unsubscribe($subscriberId));

        return new Response(200, [
            'content-type' => 'text/event-stream; charset=utf-8',
            'cache-control' => 'no-store',
            'x-accel-buffering' => 'no',
        ], $stream);
    }

    /**
     * @param iterable<mixed> $items
     * @return \Generator<int, string>
     */
    private static function chunks(iterable $items): \Generator
    {
        foreach ($items as $item) {
            if (is_string($item)) {
                yield $item;
            }
        }
    }

    private function snapshot(): ServerEvent
    {
        return new ServerEvent('snapshot', [
            'cards' => array_map(static fn(Card $card): array => $card->toArray(), $this->cards->cards(limit: $this->settings->settings->maxCards)),
            'projects' => $this->cards->projects(),
            'active_locks' => $this->locks->active(microtime(true)),
            'switches' => $this->switches->all(),
            'receiving_paused' => $this->receiving->paused,
            'screens' => $this->cards->screens(),
            'settings' => $this->settings->public(),
        ], $this->hub->lastId());
    }
}
