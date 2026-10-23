<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Settings;

use RuntimeException;

/**
 * The running viewer's settings, and where they are saved (none in tests).
 */
final class SettingsStore
{
    public function __construct(
        public readonly Settings $settings = new Settings(),
        public readonly ?SettingsFile $file = null,
    ) {}

    /**
     * @throws RuntimeException When the file cannot be written.
     */
    public function save(): void
    {
        $this->file?->save($this->settings);
    }

    /**
     * What every UI may see: the settings without the token itself.
     *
     * @return array<string, mixed>
     */
    public function public(): array
    {
        $settings = $this->settings->toArray();
        unset($settings['token'], $settings['switches'], $settings['webhooks'], $settings['log_folders']);

        return [...$settings, 'token_set' => $this->settings->token !== null];
    }
}
