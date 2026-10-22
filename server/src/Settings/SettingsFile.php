<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Settings;

use RuntimeException;

/**
 * settings.json in the viewer's config directory. Only the viewer writes it, atomically.
 *
 * Directory: PEEP_CONFIG_DIR, else %APPDATA%\peep on Windows, else
 * $XDG_CONFIG_HOME/peep, else ~/.config/peep.
 */
final readonly class SettingsFile
{
    public const string FILE_NAME = 'settings.json';

    public function __construct(
        public string $directory,
    ) {}

    /**
     * @param (callable(string): (string|false))|null $environment Reads one environment variable.
     */
    public static function inDefaultDirectory(?callable $environment = null): self
    {
        $environment ??= static fn(string $name): string|false => getenv($name);

        $configured = $environment('PEEP_CONFIG_DIR');
        if (is_string($configured) && $configured !== '') {
            return new self(rtrim($configured, '/\\'));
        }

        $appData = $environment('APPDATA');
        if (PHP_OS_FAMILY === 'Windows' && is_string($appData) && $appData !== '') {
            return new self(rtrim($appData, '/\\') . DIRECTORY_SEPARATOR . 'peep');
        }

        $xdg = $environment('XDG_CONFIG_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return new self(rtrim($xdg, '/\\') . DIRECTORY_SEPARATOR . 'peep');
        }

        $home = $environment('HOME');
        $home = is_string($home) && $home !== '' ? $home : (string) $environment('USERPROFILE');

        return new self(rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'peep');
    }

    public function path(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * A missing or broken file gives the defaults: settings must never stop the viewer.
     */
    public function load(): Settings
    {
        $contents = is_file($this->path()) ? @file_get_contents($this->path()) : false;
        $data = is_string($contents) ? json_decode($contents, true) : null;

        return Settings::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @throws RuntimeException When the file cannot be written.
     */
    public function save(Settings $settings): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Cannot create ' . $this->directory . '.');
        }

        $json = json_encode($settings->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $this->path() . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $json) === false) {
            throw new RuntimeException('Cannot write ' . $this->path() . '.');
        }

        // The file can hold the token: readable by the owner only.
        @chmod($temporary, 0600);

        if (! @rename($temporary, $this->path())) {
            @unlink($temporary);

            throw new RuntimeException('Cannot write ' . $this->path() . '.');
        }
    }
}
