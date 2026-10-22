<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Switch;

/**
 * Capture switches per project, sent to clients with every envelope answer and from
 * GET /_peep/switches. Each switch is "on", "off" or "default" (follow the app's config).
 * The viewer's toolbar changes them (POST /_peep/ui/switches).
 */
final class SwitchStore
{
    public const int TTL_SECONDS = 5;

    public const array DEFAULTS = [
        'queries' => 'default',
        'slow_queries' => 'default',
        'duplicate_queries' => 'default',
        'slow_query_ms' => null,
    ];

    /**
     * @var array<string, array<string, string|int|null>>
     */
    private array $projects = [];

    /**
     * @param array<string, array<string, string|int|null>> $saved Switches from the settings file.
     */
    public function __construct(array $saved = [])
    {
        foreach ($saved as $project => $switches) {
            $this->update($project, $switches);
        }
    }

    /**
     * @return array<string, array<string, string|int|null>> Every project that has switches set.
     */
    public function all(): array
    {
        return $this->projects;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function for(string $project): array
    {
        return $this->projects[$project] ?? self::DEFAULTS;
    }

    /**
     * @param array<string, mixed> $changes Unknown names and invalid values are ignored.
     * @return array<string, string|int|null> The project's switches after the change.
     */
    public function update(string $project, array $changes): array
    {
        $switches = $this->for($project);

        foreach ($changes as $name => $value) {
            if (! array_key_exists($name, self::DEFAULTS)) {
                continue;
            }

            if ($name === 'slow_query_ms') {
                $switches[$name] = is_int($value) && $value >= 0 ? $value : null;
            } elseif (in_array($value, ['on', 'off', 'default'], true)) {
                $switches[$name] = $value;
            }
        }

        return $this->projects[$project] = $switches;
    }

    /**
     * @return array{switches: array<string, string|int|null>, switches_ttl: int}
     */
    public function answer(string $project): array
    {
        return ['switches' => $this->for($project), 'switches_ttl' => self::TTL_SECONDS];
    }
}
