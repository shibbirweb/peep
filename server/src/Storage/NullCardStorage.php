<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Storage;

use ShibbirWeb\PeepServer\Card\Card;

/**
 * Memory only: cards live in the CardStore and end with the viewer process.
 */
final class NullCardStorage implements CardStorage
{
    public function __construct(
        private readonly string $reason = 'History is off',
    ) {}

    public function load(int $limitPerProject): array
    {
        return [];
    }

    public function save(Card $card): void {}

    public function delete(string $project, string $id): void {}

    public function clear(?string $project): void {}

    public function flush(): void {}

    public function describe(): string
    {
        return 'Memory only (' . $this->reason . ')';
    }
}
