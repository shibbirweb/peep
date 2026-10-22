<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Peep;
use ShibbirWeb\Peep\PeepFactory;

/**
 * Shared behaviour: config flag, runtime override and the Peep factory.
 */
abstract class AbstractWatcher implements WatcherInterface
{
    /**
     * @var PeepFactory
     */
    protected $factory;

    /**
     * @var array<string, mixed> The "peep" config array.
     */
    protected $config;

    /**
     * @var bool|null Set by show...() / stopShowing...(); null follows the config.
     */
    private $override;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(PeepFactory $factory, array $config)
    {
        $this->factory = $factory;
        $this->config = $config;
    }

    /**
     * The config key that turns this watcher on, e.g. "send_jobs".
     */
    abstract protected function configKey(): string;

    public function isSupported(): bool
    {
        return true;
    }

    public function isOn(): bool
    {
        if (! $this->factory->dispatcher()->isActive()) {
            return false;
        }

        return $this->override !== null ? $this->override : $this->configured();
    }

    public function enable(): void
    {
        $this->override = true;
    }

    public function disable(): void
    {
        $this->override = false;
    }

    /**
     * Run a callback with the watcher on, then restore the previous state.
     *
     * @return mixed The callback's result.
     */
    public function during(callable $callback)
    {
        $previous = $this->override;
        $this->override = true;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    protected function configured(): bool
    {
        $key = $this->configKey();

        return Config::toBool(isset($this->config[$key]) ? $this->config[$key] : false, false);
    }

    protected function override(): ?bool
    {
        return $this->override;
    }

    protected function peep(?string $card = null): Peep
    {
        return $this->factory->make($card);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    protected function serialize($value): array
    {
        return $this->factory->services()->serializer->serialize($value);
    }

    /**
     * Header and field names whose values must not leave the app.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function redact(array $values): array
    {
        $sensitive = ['authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-csrf-token', 'x-xsrf-token', 'php-auth-pw', 'password', 'password_confirmation', 'token', 'secret'];

        foreach ($values as $name => $value) {
            if (in_array(strtolower((string) $name), $sensitive, true)) {
                $values[$name] = '***';
            }
        }

        return $values;
    }
}
