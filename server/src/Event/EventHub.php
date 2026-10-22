<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Event;

use Amp\Pipeline\Queue;

/**
 * Numbers events, keeps a short history for reconnecting clients, and pushes
 * every event to each connected UI stream.
 */
final class EventHub
{
    private int $lastId = 0;

    /**
     * @var list<ServerEvent>
     */
    private array $history = [];

    /**
     * Subscriber id => queue of SSE text chunks. Queue cannot carry its value type from
     * the constructor, so readers check that each item is a string (StreamController).
     *
     * @var array<int, Queue<mixed>>
     */
    private array $subscribers = [];

    private int $nextSubscriberId = 1;

    /**
     * @param positive-int $historySize
     * @param positive-int $subscriberBuffer Chunks buffered per slow client before it is dropped.
     */
    public function __construct(
        private readonly int $historySize = 1000,
        private readonly int $subscriberBuffer = 1000,
    ) {}

    public function lastId(): int
    {
        return $this->lastId;
    }

    /**
     * @param list<ServerEvent> $events
     */
    public function publish(array $events): void
    {
        foreach ($events as $event) {
            $numbered = $event->withId(++$this->lastId);

            $this->history[] = $numbered;
            if (count($this->history) > $this->historySize) {
                array_shift($this->history);
            }

            $this->broadcast($numbered->toSse());
        }
    }

    /**
     * Events after the given id, or null when they are no longer in the history
     * (the client must then load a fresh snapshot).
     *
     * @return list<ServerEvent>|null
     */
    public function since(int $id): ?array
    {
        if ($id >= $this->lastId) {
            return [];
        }

        $oldest = $this->history[0]->id ?? $this->lastId + 1;
        if ($id + 1 < $oldest) {
            return null;
        }

        return array_values(array_filter($this->history, static fn(ServerEvent $event): bool => $event->id > $id));
    }

    /**
     * @return array{int, Queue<mixed>} Subscriber id and the queue its stream reads from.
     */
    public function subscribe(): array
    {
        $id = $this->nextSubscriberId++;
        $queue = new Queue($this->subscriberBuffer);
        $this->subscribers[$id] = $queue;

        return [$id, $queue];
    }

    public function unsubscribe(int $id): void
    {
        $queue = $this->subscribers[$id] ?? null;
        unset($this->subscribers[$id]);

        if ($queue !== null && ! $queue->isComplete()) {
            $queue->complete();
        }
    }

    /**
     * Ends every UI stream. Called on shutdown: open streams would otherwise keep the server waiting.
     */
    public function closeAll(): void
    {
        foreach (array_keys($this->subscribers) as $id) {
            $this->unsubscribe($id);
        }
    }

    public function subscriberCount(): int
    {
        return count($this->subscribers);
    }

    /**
     * Sends raw SSE text (e.g. a keep-alive comment) to every subscriber.
     */
    public function broadcast(string $chunk): void
    {
        foreach ($this->subscribers as $id => $queue) {
            if ($queue->isComplete() || $queue->isDisposed()) {
                unset($this->subscribers[$id]);
                continue;
            }

            // pushAsync() does not wait for the client to read. When the client disconnects,
            // its stream disposes the queue, the pending push fails and the client is removed.
            $queue->pushAsync($chunk)->catch(fn() => $this->unsubscribe($id))->ignore();
        }
    }
}
