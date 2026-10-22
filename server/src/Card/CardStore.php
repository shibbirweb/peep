<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Card;

use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Protocol\Envelope;

/**
 * In-memory cards, grouped by project, oldest dropped first when a project is full.
 * Every change returns the events the UI needs to stay in sync.
 */
final class CardStore
{
    /**
     * @var array<string, array<string, Card>> project => card id => card, in arrival order
     */
    private array $projects = [];

    /**
     * @param positive-int $maxCardsPerProject
     */
    public function __construct(
        private readonly int $maxCardsPerProject = 10000,
    ) {}

    /**
     * @return list<ServerEvent> Without ids; the hub numbers them when publishing.
     */
    public function apply(Envelope $envelope, float $now): array
    {
        $project = $envelope->project;
        $events = [];
        $cardPayloads = [];

        foreach ($envelope->payloads as $payload) {
            if ($payload->type === 'remove') {
                return $this->remove($project, $envelope->card);
            }

            // Screen and app commands act on the screen, not on a card.
            if ($payload->isCommand()) {
                $events = [...$events, ...$this->command($payload->type, $project)];
                continue;
            }

            // A new screen clears the project, then shows its title as the first card.
            if ($payload->type === 'new_screen') {
                $events = [...$events, ...$this->clear($project)];
            }

            $cardPayloads[] = $payload;
        }

        if ($cardPayloads === []) {
            return $events;
        }

        $card = $this->projects[$project][$envelope->card] ?? null;
        $isNew = $card === null;
        $card ??= Card::fromEnvelope($envelope, $now);

        foreach ($cardPayloads as $payload) {
            $card->apply($payload, $now);
        }

        if ($isNew) {
            $events = [...$events, ...$this->add($card)];
        }

        $events[] = new ServerEvent($isNew ? 'card.created' : 'card.updated', $card->toArray());

        return $events;
    }

    /**
     * @return list<ServerEvent>
     */
    public function clear(?string $project = null): array
    {
        if ($project === null) {
            $this->projects = [];
        } else {
            unset($this->projects[$project]);
        }

        return [new ServerEvent('screen.cleared', ['project' => $project])];
    }

    /**
     * Newest cards last.
     *
     * @return list<Card>
     */
    public function cards(?string $project = null, int $limit = 1000): array
    {
        $cards = $project === null
            ? array_merge(...array_values(array_map(array_values(...), $this->projects)))
            : array_values($this->projects[$project] ?? []);

        usort($cards, static fn(Card $a, Card $b): int => $a->createdAt <=> $b->createdAt);

        return array_slice($cards, -$limit);
    }

    public function find(string $project, string $id): ?Card
    {
        return $this->projects[$project][$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function projects(): array
    {
        return array_map(strval(...), array_keys($this->projects));
    }

    /**
     * @return list<ServerEvent>
     */
    private function command(string $type, string $project): array
    {
        return match ($type) {
            'clear_screen' => $this->clear($project),
            'clear_all' => $this->clear(),
            // show_app / hide_app are for the desktop app (DT-07 and later); the browser ignores them.
            default => [new ServerEvent('app.command', ['command' => $type, 'project' => $project])],
        };
    }

    /**
     * @return list<ServerEvent>
     */
    private function add(Card $card): array
    {
        $this->projects[$card->project][$card->id] = $card;

        $events = [];
        while (count($this->projects[$card->project]) > $this->maxCardsPerProject) {
            $oldestId = (string) array_key_first($this->projects[$card->project]);
            unset($this->projects[$card->project][$oldestId]);
            $events[] = new ServerEvent('card.removed', ['project' => $card->project, 'id' => $oldestId]);
        }

        return $events;
    }

    /**
     * @return list<ServerEvent>
     */
    private function remove(string $project, string $id): array
    {
        if (! isset($this->projects[$project][$id])) {
            return [];
        }

        unset($this->projects[$project][$id]);

        return [new ServerEvent('card.removed', ['project' => $project, 'id' => $id])];
    }
}
