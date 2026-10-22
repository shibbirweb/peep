<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

use ShibbirWeb\PeepServer\Settings\SettingsStore;

/**
 * The rules, saved in the settings file (WH-11).
 */
final class WebhookRules
{
    /**
     * @var array<string, Rule> id => rule, in the user's order
     */
    private array $rules = [];

    public function __construct(
        private readonly SettingsStore $settings = new SettingsStore(),
    ) {
        foreach ($settings->settings->webhooks as $data) {
            try {
                $rule = Rule::fromArray($data);
                $this->rules[$rule->id] = $rule;
            } catch (InvalidRuleException) {
                // A broken rule in a hand-edited file is skipped, not fatal.
            }
        }
    }

    /**
     * @return list<Rule>
     */
    public function all(): array
    {
        return array_values($this->rules);
    }

    public function find(string $id): ?Rule
    {
        return $this->rules[$id] ?? null;
    }

    public function save(Rule $rule): void
    {
        $this->rules[$rule->id] = $rule;
        $this->persist();
    }

    public function delete(string $id): bool
    {
        if (! isset($this->rules[$id])) {
            return false;
        }

        unset($this->rules[$id]);
        $this->persist();

        return true;
    }

    /**
     * @param list<Rule> $rules
     */
    public function import(array $rules, bool $replace): void
    {
        if ($replace) {
            $this->rules = [];
        }

        foreach ($rules as $rule) {
            $this->rules[$rule->id] = $rule;
        }

        $this->persist();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn(Rule $rule): array => $rule->toArray(), $this->all());
    }

    private function persist(): void
    {
        $this->settings->settings->webhooks = $this->toArray();

        try {
            $this->settings->save();
        } catch (\RuntimeException) {
            // The rules work for this run even when the file cannot be written.
        }
    }
}
