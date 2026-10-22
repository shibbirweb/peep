<?php

namespace ShibbirWeb\Peep\Origin;

/**
 * Maps paths inside a container or VM (remote_path) to the same files on the
 * developer's machine (local_path), so "open in IDE" links work.
 */
final class PathMapper
{
    /**
     * @var string|null
     */
    private $remotePath;

    /**
     * @var string|null
     */
    private $localPath;

    public function __construct(?string $remotePath, ?string $localPath)
    {
        $this->remotePath = $remotePath !== null ? self::withoutTrailingSlash($remotePath) : null;
        $this->localPath = $localPath !== null ? self::withoutTrailingSlash($localPath) : null;
    }

    public function map(string $path): string
    {
        if ($this->remotePath === null || $this->localPath === null) {
            return $path;
        }

        if ($path === $this->remotePath) {
            return $this->localPath;
        }

        $prefix = $this->remotePath . '/';
        if (strpos($path, $prefix) === 0) {
            return $this->localPath . '/' . substr($path, strlen($prefix));
        }

        return $path;
    }

    private static function withoutTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/\\');

        return $trimmed === '' ? $path : $trimmed;
    }
}
