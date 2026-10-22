<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Lock;

/**
 * Pause locks created by peep()->pause(). The paused app polls its lock until the
 * user clicks Continue or Stop in the viewer. Locks expire after an hour.
 */
final class LockRegistry
{
    public const int TTL_SECONDS = 3600;

    /**
     * @var array<string, array{project: string, card: string, active: bool, stop: bool, created_at: float}>
     */
    private array $locks = [];

    public function register(string $name, string $project, string $card, float $now): void
    {
        $this->prune($now);

        $this->locks[$name] = [
            'project' => $project,
            'card' => $card,
            'active' => true,
            'stop' => false,
            'created_at' => $now,
        ];
    }

    /**
     * @return array{active: bool, stop: bool}|null Null for unknown or expired locks.
     */
    public function state(string $name, float $now): ?array
    {
        $this->prune($now);
        $lock = $this->locks[$name] ?? null;

        return $lock === null ? null : ['active' => $lock['active'], 'stop' => $lock['stop']];
    }

    /**
     * @return bool False when the lock is unknown or already released.
     */
    public function release(string $name, bool $stop): bool
    {
        if (! ($this->locks[$name]['active'] ?? false)) {
            return false;
        }

        $this->locks[$name]['active'] = false;
        $this->locks[$name]['stop'] = $stop;

        return true;
    }

    /**
     * Names of locks still waiting, so a reconnecting UI can show their buttons.
     *
     * @return list<string>
     */
    public function active(float $now): array
    {
        $this->prune($now);

        return array_keys(array_filter($this->locks, static fn(array $lock): bool => $lock['active']));
    }

    private function prune(float $now): void
    {
        $this->locks = array_filter(
            $this->locks,
            static fn(array $lock): bool => $now - $lock['created_at'] < self::TTL_SECONDS,
        );
    }
}
