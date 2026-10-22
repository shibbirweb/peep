<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Card;

use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Protocol\Envelope;
use ShibbirWeb\PeepServer\Storage\CardStorage;
use ShibbirWeb\PeepServer\Storage\NullCardStorage;

/**
 * Cards in memory, grouped by project, oldest dropped first when a project is full.
 * Every change returns the events the UI needs to stay in sync, and is passed on to
 * the storage so the cards survive a restart.
 *
 * A new screen archives the project's cards instead of dropping them; the last
 * MAX_SCREENS archives per project stay available (memory only).
 */
final class CardStore
{
    public const int MAX_SCREENS = 10;

    /**
     * @var array<string, array<string, Card>> project => card id => card, in arrival order
     */
    private array $projects = [];

    /**
     * @var array<string, list<array{id: string, project: string, title: string, archived_at: float, cards: list<Card>}>> project => archives, newest last
     */
    private array $screens = [];

    /**
     * @var array<string, string> project => title of the current screen
     */
    private array $screenTitles = [];

    private int $screenCounter = 0;

    /**
     * @param positive-int $maxCardsPerProject
     */
    public function __construct(
        private readonly int $maxCardsPerProject = 10000,
        private readonly CardStorage $storage = new NullCardStorage(),
    ) {}

    /**
     * Loads the cards kept by the storage (at start-up).
     */
    public function restore(): int
    {
        $cards = $this->storage->load($this->maxCardsPerProject);

        foreach ($cards as $card) {
            $this->projects[$card->project][$card->id] = $card;
        }

        return count($cards);
    }

    public function storage(): CardStorage
    {
        return $this->storage;
    }

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

            // A new screen archives the project's cards, then shows its title as the first card.
            if ($payload->type === 'new_screen') {
                $title = $payload->content['title'] ?? '';
                $events = [...$events, ...$this->newScreen($project, is_string($title) ? $title : '', $now)];
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

        $this->storage->save($card);
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

        $this->storage->clear($project);

        return [new ServerEvent('screen.cleared', ['project' => $project])];
    }

    /**
     * Archives the project's current cards and starts an empty screen.
     *
     * @return list<ServerEvent>
     */
    public function newScreen(string $project, string $title, float $now): array
    {
        $events = [];
        $cards = array_values($this->projects[$project] ?? []);

        if ($cards !== []) {
            $archive = [
                'id' => 'screen-' . ++$this->screenCounter,
                'project' => $project,
                'title' => $this->screenTitles[$project] ?? '',
                'archived_at' => $now,
                'cards' => $cards,
            ];

            $this->screens[$project][] = $archive;
            if (count($this->screens[$project]) > self::MAX_SCREENS) {
                array_shift($this->screens[$project]);
            }

            $events[] = new ServerEvent('screen.archived', self::summary($archive));
        }

        $this->screenTitles[$project] = $title;

        return [...$events, ...$this->clear($project)];
    }

    /**
     * Archived screens, newest first, without their cards.
     *
     * @return list<array{id: string, project: string, title: string, archived_at: float, count: int}>
     */
    public function screens(?string $project = null): array
    {
        $archives = $project === null ? array_merge(...array_values($this->screens)) : ($this->screens[$project] ?? []);
        usort($archives, static fn(array $a, array $b): int => $b['archived_at'] <=> $a['archived_at']);

        return array_map(self::summary(...), $archives);
    }

    /**
     * @return array{id: string, project: string, title: string, archived_at: float, count: int, cards: list<Card>}|null
     */
    public function screen(string $id): ?array
    {
        foreach ($this->screens as $archives) {
            foreach ($archives as $archive) {
                if ($archive['id'] === $id) {
                    return [...self::summary($archive), 'cards' => $archive['cards']];
                }
            }
        }

        return null;
    }

    /**
     * The newest cards of each project (or of one), newest last.
     *
     * @param int $limit Per project, so a busy project never pushes a quiet one out.
     * @return list<Card>
     */
    public function cards(?string $project = null, int $limit = 1000): array
    {
        $projects = $project === null ? $this->projects : [$project => $this->projects[$project] ?? []];

        $byTime = static function (array $cards): array {
            usort($cards, static fn(Card $a, Card $b): int => $a->createdAt <=> $b->createdAt);

            return $cards;
        };

        return $byTime(array_merge(...array_values(array_map(
            static fn(array $projectCards): array => array_slice($byTime(array_values($projectCards)), -$limit),
            $projects,
        ))));
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
     * @param array{id: string, project: string, title: string, archived_at: float, cards: list<Card>} $archive
     * @return array{id: string, project: string, title: string, archived_at: float, count: int}
     */
    private static function summary(array $archive): array
    {
        return [
            'id' => $archive['id'],
            'project' => $archive['project'],
            'title' => $archive['title'],
            'archived_at' => $archive['archived_at'],
            'count' => count($archive['cards']),
        ];
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
            $this->storage->delete($card->project, $oldestId);
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
        $this->storage->delete($project, $id);

        return [new ServerEvent('card.removed', ['project' => $project, 'id' => $id])];
    }
}
