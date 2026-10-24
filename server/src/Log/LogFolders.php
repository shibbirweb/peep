<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Log;

use RuntimeException;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Settings\SettingsStore;

/**
 * Each project's log folder. Apps report theirs with every envelope (meta.logs: Laravel's
 * storage/logs); a folder chosen in the Logs tab wins over that until it is removed. Both are
 * saved in the settings, so the Logs tab works before the app sends again.
 */
final class LogFolders
{
    /**
     * @var array<string, float> Per project: when it was last cleared, for the debug report.
     */
    private array $clearedAt = [];

    public function __construct(
        private readonly SettingsStore $settings,
    ) {}

    /**
     * What an app reported. A folder chosen by hand stays; only its time zone follows the app.
     *
     * @param array{path: string, timezone: string} $logs
     * @return list<ServerEvent>
     */
    public function reported(string $project, array $logs): array
    {
        $current = $this->settings->settings->logFolders[$project] ?? null;

        $next = $current !== null && $current['manual']
            ? [...$current, 'timezone' => $logs['timezone']]
            : ['path' => $logs['path'], 'timezone' => $logs['timezone'], 'manual' => false];

        return $next === $current ? [] : $this->store($project, $next);
    }

    /**
     * A folder chosen in the Logs tab, or null to go back to the one the app reports.
     *
     * @return list<ServerEvent>
     */
    public function choose(string $project, ?string $path): array
    {
        $current = $this->settings->settings->logFolders[$project] ?? null;

        return $this->store($project, $path === null ? null : [
            'path' => $path,
            'timezone' => $current['timezone'] ?? 'UTC',
            'manual' => true,
        ]);
    }

    /**
     * @return array{path: string, timezone: string, manual: bool}|null
     */
    public function for(string $project): ?array
    {
        return $this->settings->settings->logFolders[$project] ?? null;
    }

    /**
     * @return list<array{project: string, path: string, timezone: string, manual: bool, exists: bool}>
     */
    public function all(): array
    {
        $folders = [];
        foreach ($this->settings->settings->logFolders as $project => $folder) {
            $folders[] = ['project' => $project, ...$folder, 'exists' => is_dir($folder['path'])];
        }

        usort($folders, static fn(array $a, array $b): int => strcmp($a['project'], $b['project']));

        return $folders;
    }

    /**
     * Marks the project (or every project) as cleared now: the debug report then shows only
     * log entries written after it.
     */
    public function markCleared(?string $project, float $now): void
    {
        foreach ($project === null ? array_keys($this->settings->settings->logFolders) : [$project] as $name) {
            $this->clearedAt[$name] = $now;
        }
    }

    public function clearedAt(string $project): ?float
    {
        return $this->clearedAt[$project] ?? null;
    }

    /**
     * @param array{path: string, timezone: string, manual: bool}|null $folder
     * @return list<ServerEvent>
     */
    private function store(string $project, ?array $folder): array
    {
        $folders = $this->settings->settings->logFolders;

        if ($folder === null) {
            unset($folders[$project]);
        } else {
            $folders[$project] = $folder;
        }

        $this->settings->settings->logFolders = $folders;

        try {
            $this->settings->save();
        } catch (RuntimeException) {
            // Still used while the viewer runs; the next change tries to save again.
        }

        return [new ServerEvent('logs.folders', ['folders' => $this->all()])];
    }
}
