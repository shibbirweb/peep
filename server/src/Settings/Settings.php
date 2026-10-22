<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Settings;

use TypeError;
use ValueError;

/**
 * The viewer's settings. Each property validates what it is given, so an invalid
 * value can never be stored, whether it comes from the file or from the settings page.
 */
final class Settings
{
    public const array IDES = ['phpstorm', 'vscode', 'cursor', 'zed', 'sublime', 'none'];

    /**
     * Settings that only take effect when the viewer starts again.
     */
    public const array RESTART_REQUIRED = ['host', 'port', 'token', 'history', 'max_cards'];

    public const int MIN_TOKEN_LENGTH = 16;

    public DisplayMode $displayMode = DisplayMode::Browser;

    public string $ide = 'phpstorm' {
        set {
            if (! in_array($value, self::IDES, true)) {
                throw new InvalidSettingException('ide', 'Unknown editor: ' . $value . '.');
            }

            $this->ide = $value;
        }
    }

    /**
     * Applied to file paths before building editor links, after the client's own mapping.
     * Validated by update() and fromArray().
     *
     * @var list<array{from: string, to: string}>
     */
    public private(set) array $pathMappings = [];

    public string $host = '127.0.0.1' {
        set {
            $host = trim($value);

            if ($host === '' || preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host) !== 1) {
                throw new InvalidSettingException('host', 'The host must be an IP address or a host name.');
            }

            $this->host = $host;
        }
    }

    public int $port = 23517 {
        set {
            if ($value < 1 || $value > 65535) {
                throw new InvalidSettingException('port', 'The port must be a number from 1 to 65535.');
            }

            $this->port = $value;
        }
    }

    public Theme $theme = Theme::System;

    /**
     * Shared secret that apps on other machines (or Docker on Linux) send as X-Peep-Token.
     * Required before the viewer listens beyond loopback.
     */
    public ?string $token = null {
        set {
            if ($value === null || $value === '') {
                $this->token = null;

                return;
            }

            if (strlen($value) < self::MIN_TOKEN_LENGTH || strlen($value) > 256 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
                throw new InvalidSettingException('token', 'The token must be 16 to 256 letters, digits, "-" or "_".');
            }

            $this->token = $value;
        }
    }

    public int $maxCards = 10000 {
        set {
            if ($value < 100 || $value > 50000) {
                throw new InvalidSettingException('max_cards', 'Max cards must be from 100 to 50,000.');
            }

            $this->maxCards = $value;
        }
    }

    /**
     * Keep cards across restarts (SQLite, when pdo_sqlite is available).
     */
    public bool $history = true;

    /**
     * Desktop notifications for notify() and exceptions.
     */
    public bool $notifications = false;

    /**
     * Query debugging switches per project, as set in the toolbar.
     *
     * @var array<string, array<string, string|int|null>>
     */
    public array $switches = [];

    /**
     * Builds settings from the file. Invalid or unknown values are skipped, so a
     * hand-edited file with one mistake still loads.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $settings = new self();

        foreach ($data as $key => $value) {
            try {
                $settings->set((string) $key, $value);
            } catch (InvalidSettingException) {
                // Keep the default.
            }
        }

        return $settings;
    }

    /**
     * Applies changes from the settings page; the first invalid value stops it.
     *
     * @param array<mixed> $changes
     * @return list<string> Names of the changed settings that need a restart.
     *
     * @throws InvalidSettingException
     */
    public function update(array $changes): array
    {
        $before = $this->toArray();
        $copy = clone $this;

        foreach ($changes as $key => $value) {
            $copy->set((string) $key, $value);
        }

        foreach (get_object_vars($copy) as $property => $value) {
            $this->{$property} = $value;
        }

        $after = $this->toArray();

        return array_values(array_filter(
            self::RESTART_REQUIRED,
            static fn(string $key): bool => $before[$key] !== $after[$key],
        ));
    }

    /**
     * @return array{display_mode: string, ide: string, path_mappings: list<array{from: string, to: string}>, host: string, port: int, theme: string, token: string|null, max_cards: int, history: bool, notifications: bool, switches: array<string, array<string, string|int|null>>}
     */
    public function toArray(): array
    {
        return [
            'display_mode' => $this->displayMode->value,
            'ide' => $this->ide,
            'path_mappings' => $this->pathMappings,
            'host' => $this->host,
            'port' => $this->port,
            'theme' => $this->theme->value,
            'token' => $this->token,
            'max_cards' => $this->maxCards,
            'history' => $this->history,
            'notifications' => $this->notifications,
            'switches' => $this->switches,
        ];
    }

    /**
     * @throws InvalidSettingException
     */
    private function set(string $key, mixed $value): void
    {
        try {
            match ($key) {
                'display_mode' => $this->displayMode = DisplayMode::from(self::string($key, $value)),
                'ide' => $this->ide = self::string($key, $value),
                'path_mappings' => $this->pathMappings = self::pathMappings($value),
                'host' => $this->host = self::string($key, $value),
                'port' => $this->port = is_int($value) ? $value : throw new InvalidSettingException($key, 'The port must be a number.'),
                'theme' => $this->theme = Theme::from(self::string($key, $value)),
                'token' => $this->token = $value === null ? null : self::string($key, $value),
                'max_cards' => $this->maxCards = is_int($value) ? $value : throw new InvalidSettingException($key, 'Max cards must be a number.'),
                'history' => $this->history = is_bool($value) ? $value : throw new InvalidSettingException($key, 'History must be true or false.'),
                'notifications' => $this->notifications = is_bool($value) ? $value : throw new InvalidSettingException($key, 'Notifications must be true or false.'),
                'switches' => $this->switches = self::switches($value),
                default => throw new InvalidSettingException($key, 'Unknown setting: ' . $key . '.'),
            };
        } catch (ValueError | TypeError) {
            throw new InvalidSettingException($key, 'Invalid value for ' . $key . '.');
        }
    }

    private static function string(string $key, mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidSettingException($key, ucfirst(str_replace('_', ' ', $key)) . ' must be text.');
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private static function pathMappings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidSettingException('path_mappings', 'Path mappings must be a list.');
        }

        $mappings = [];
        foreach ($value as $mapping) {
            $from = is_array($mapping) && is_string($mapping['from'] ?? null) ? trim($mapping['from']) : '';
            $to = is_array($mapping) && is_string($mapping['to'] ?? null) ? trim($mapping['to']) : '';

            if ($from === '' || $to === '') {
                throw new InvalidSettingException('path_mappings', 'Each path mapping needs a "from" and a "to" path.');
            }

            $mappings[] = ['from' => $from, 'to' => $to];
        }

        return $mappings;
    }

    /**
     * @return array<string, array<string, string|int|null>>
     */
    private static function switches(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidSettingException('switches', 'Switches must be an object.');
        }

        $switches = [];
        foreach ($value as $project => $projectSwitches) {
            if (! is_string($project) || ! is_array($projectSwitches)) {
                continue;
            }

            foreach ($projectSwitches as $name => $state) {
                if (is_string($name) && (is_string($state) || is_int($state) || $state === null)) {
                    $switches[$project][$name] = $state;
                }
            }
        }

        return $switches;
    }
}
