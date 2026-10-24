<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Log;

/**
 * The Logs tab's folder picker: the folders inside one folder, so a log folder is chosen by
 * clicking instead of typing its path (a browser cannot tell a page where a folder is). Only
 * folder names and the number of .log files are shown, never other files.
 */
final readonly class FolderBrowser
{
    public const int MAX_FOLDERS = 1000;

    private const string LARAVEL_LOGS = 'storage' . DIRECTORY_SEPARATOR . 'logs';

    private function __construct() {}

    /**
     * $start, or the nearest folder above it that exists (the home folder when there is none).
     * Null when that cannot be read either.
     *
     * @param (callable(string): (string|false))|null $environment Reads an environment variable; getenv by default.
     * @return array{path: string, parent: string|null, home: string|null, logs: int, laravel_logs: string|null, folders: list<array{name: string, path: string, laravel: bool}>, more: bool}|null
     */
    public static function browse(?string $start, ?callable $environment = null): ?array
    {
        $home = self::home($environment ?? static fn(string $name): string|false => getenv($name));
        $folder = self::existing($start) ?? $home ?? self::existing(DIRECTORY_SEPARATOR);

        if ($folder === null || ! is_readable($folder)) {
            return null;
        }

        $names = scandir($folder);
        if ($names === false) {
            return null;
        }

        $folders = [];
        $logs = 0;
        foreach ($names as $name) {
            // Hidden entries, "." and "..": never log files the Logs tab reads, nor folders to show.
            if ($name === '' || $name[0] === '.') {
                continue;
            }

            $path = self::join($folder, $name);

            if (LogFiles::resolve($folder, $name) !== null) {
                $logs++;
            }

            if (is_dir($path)) {
                $folders[] = ['name' => $name, 'path' => $path, 'laravel' => is_dir(self::join($path, self::LARAVEL_LOGS))];
            }
        }

        usort($folders, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $parent = dirname($folder);
        $laravelLogs = self::join($folder, self::LARAVEL_LOGS);

        return [
            'path' => $folder,
            'parent' => $parent !== $folder ? $parent : null,
            'home' => $home,
            'logs' => $logs,
            'laravel_logs' => is_dir($laravelLogs) ? $laravelLogs : null,
            'folders' => array_slice($folders, 0, self::MAX_FOLDERS),
            'more' => count($folders) > self::MAX_FOLDERS,
        ];
    }

    /**
     * @param callable(string): (string|false) $environment
     */
    private static function home(callable $environment): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $name) {
            $value = $environment($name);
            $home = is_string($value) && $value !== '' ? self::existing($value) : null;

            if ($home !== null) {
                return $home;
            }
        }

        return null;
    }

    private static function existing(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        for ($level = 0; $level < 64; $level++) {
            $real = realpath($path);

            if ($real !== false && is_dir($real)) {
                return $real;
            }

            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }

            $path = $parent;
        }

        return null;
    }

    private static function join(string $folder, string $name): string
    {
        return rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $name;
    }
}
