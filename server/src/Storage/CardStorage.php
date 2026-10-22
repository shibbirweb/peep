<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Storage;

use ShibbirWeb\PeepServer\Card\Card;

/**
 * Keeps cards across viewer restarts. The CardStore stays the working set in memory and
 * tells the storage about every change; implementations may buffer until flush().
 */
interface CardStorage
{
    /**
     * @param positive-int $limitPerProject
     * @return list<Card> Oldest first.
     */
    public function load(int $limitPerProject): array;

    public function save(Card $card): void;

    public function delete(string $project, string $id): void;

    /**
     * @param string|null $project Null clears every project.
     */
    public function clear(?string $project): void;

    /**
     * Writes buffered changes.
     */
    public function flush(): void;

    /**
     * Shown in the settings page, e.g. "SQLite (~/.config/peep/history.sqlite)".
     */
    public function describe(): string;
}
