<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Sms;

use ShibbirWeb\PeepServer\Event\ServerEvent;

/**
 * The SMS inbox, in memory, oldest dropped first.
 */
final class SmsStore
{
    /**
     * @var array<string, Sms> id => message, oldest first
     */
    private array $messages = [];

    /**
     * @param positive-int $maxMessages
     */
    public function __construct(
        private readonly int $maxMessages = 2000,
    ) {}

    /**
     * @return list<ServerEvent>
     */
    public function add(Sms $sms): array
    {
        $this->messages[$sms->id] = $sms;

        $events = [];
        while (count($this->messages) > $this->maxMessages) {
            $oldest = array_shift($this->messages);
            $events[] = new ServerEvent('sms.removed', ['id' => $oldest->id]);
        }

        $events[] = new ServerEvent('sms.received', $sms->toArray());

        return $events;
    }

    public function find(string $id): ?Sms
    {
        return $this->messages[$id] ?? null;
    }

    /**
     * Marks every message to one number as read (the thread was opened).
     *
     * @return list<ServerEvent>
     */
    public function markThreadRead(string $project, string $to): array
    {
        $events = [];

        foreach ($this->messages as $sms) {
            if (! $sms->read && $sms->project === $project && $sms->to === $to) {
                $sms->read = true;
                $events[] = new ServerEvent('sms.updated', ['id' => $sms->id, 'read' => true]);
            }
        }

        return $events;
    }

    /**
     * @return list<ServerEvent>
     */
    public function delete(string $id): array
    {
        if (! isset($this->messages[$id])) {
            return [];
        }

        unset($this->messages[$id]);

        return [new ServerEvent('sms.removed', ['id' => $id])];
    }

    /**
     * @return list<ServerEvent>
     */
    public function clear(?string $project = null): array
    {
        $this->messages = $project === null ? [] : array_filter($this->messages, static fn(Sms $sms): bool => $sms->project !== $project);

        return [new ServerEvent('sms.cleared', ['project' => $project])];
    }

    /**
     * Newest first.
     *
     * @return list<Sms>
     */
    public function all(): array
    {
        return array_reverse(array_values($this->messages));
    }
}
