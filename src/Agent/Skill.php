<?php

namespace ShibbirWeb\Peep\Agent;

/**
 * The Agent Skill that teaches AI agents to debug with Peep (resources/skills/peep/SKILL.md).
 */
final class Skill
{
    public const PROJECT_DIRECTORY = '.claude/skills/peep';

    private function __construct()
    {
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/skills/peep/SKILL.md';
    }

    public static function contents(): ?string
    {
        $contents = @file_get_contents(self::path());

        return is_string($contents) ? $contents : null;
    }

    /**
     * Writes SKILL.md into $directory, creating it. The written file, or null when that failed.
     */
    public static function install(string $directory): ?string
    {
        $contents = self::contents();
        if ($contents === null) {
            return null;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            return null;
        }

        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'SKILL.md';

        return @file_put_contents($file, $contents) !== false ? $file : null;
    }
}
