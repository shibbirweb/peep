<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use ShibbirWeb\Peep\Laravel\PeepServiceProvider;
use ShibbirWeb\Peep\PeepFactory;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Transport\AvailabilityCache;

/**
 * A Laravel app (Orchestra Testbench) with Peep enabled and a recording transport.
 *
 * Written for every Testbench from 3.5 (Laravel 5.5, PHPUnit 6) to 11 (Laravel 13,
 * PHPUnit 12): only hooks and assertions that exist in all of them.
 */
abstract class LaravelTestCase extends TestCase
{
    /**
     * @var RecordingTransport
     */
    protected $transport;

    protected function tearDown(): void
    {
        PeepFactory::setInstance(null);
        AvailabilityCache::reset();

        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app)
    {
        return [PeepServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app)
    {
        $config = $app['config'];
        $config->set('app.name', 'Peep Laravel Tests');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('mail.driver', 'array');
        $config->set('mail.default', 'array');

        $config->set('peep.enabled', true);
        foreach ($this->peepConfig() as $key => $value) {
            $config->set('peep.' . $key, $value);
        }

        $this->transport = new RecordingTransport();
        $transport = $this->transport;

        $app->singleton(PeepFactory::class, function ($app) use ($transport) {
            return PeepServiceProvider::makeFactory($app, $transport);
        });
    }

    /**
     * Peep settings for this test class (merged into config("peep")).
     *
     * @return array<string, mixed>
     */
    protected function peepConfig(): array
    {
        return [];
    }

    /**
     * The app, typed as never null (Testbench types it as nullable).
     */
    protected function laravel(): \Illuminate\Foundation\Application
    {
        if ($this->app === null) {
            throw new \LogicException('The Laravel app is not created yet.');
        }

        return $this->app;
    }

    /**
     * Payloads of one type, in order.
     *
     * @phpstan-impure
     * @return list<array<string, mixed>>
     */
    protected function payloadsOfType(string $type): array
    {
        $found = [];
        foreach ($this->transport->payloads() as $payload) {
            if ($payload['type'] === $type) {
                $found[] = $payload;
            }
        }

        return $found;
    }

    /**
     * Requests whose first payload has the given type.
     *
     * @phpstan-impure
     * @return list<array<string, mixed>>
     */
    protected function requestsOfType(string $type): array
    {
        $found = [];
        foreach ($this->transport->requests as $request) {
            if ($request['body']['payloads'][0]['type'] === $type) {
                $found[] = $request['body'];
            }
        }

        return $found;
    }

    protected function laravelVersionAtLeast(string $version): bool
    {
        return version_compare($this->laravel()->version(), $version, '>=');
    }
}
