<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\DB;
use ShibbirWeb\Peep\Laravel\Watcher\QueryWatcher;
use ShibbirWeb\Peep\PeepFactory;

final class ProviderTest extends LaravelTestCase
{
    protected function peepConfig(): array
    {
        return ['send_queries' => true];
    }

    public function testProviderSharesTheFactoryWithThePeepHelper(): void
    {
        $factory = $this->laravel()->make(PeepFactory::class);

        $this->assertSame($factory, PeepFactory::instance());
        $this->assertTrue($factory->dispatcher()->isEnabled());
        $this->assertSame('Peep Laravel Tests', $factory->config()->project());
    }

    public function testTheAppReportsItsLogFolder(): void
    {
        $config = $this->laravel()->make(PeepFactory::class)->config();

        $this->assertSame(rtrim($this->laravel()->storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'logs', $config->logPath());
        $this->assertSame($this->laravel()->make('config')->get('app.timezone'), $config->logTimezone());

        peep('with logs');
        $mine = null;
        foreach ($this->transport->requests as $request) {
            if (isset($request['body']['payloads'][0]['content']['values'][0]['value']) && $request['body']['payloads'][0]['content']['values'][0]['value'] === 'with logs') {
                $mine = $request;
            }
        }
        $this->assertNotNull($mine, 'The card was sent.');
        $this->assertSame(['path' => $config->logPath(), 'timezone' => $config->logTimezone()], $mine['body']['meta']['logs']);
    }

    public function testPeepCallsFromTheAppAreSent(): void
    {
        $line = __LINE__ + 1;
        peep('from a Laravel app');

        $payload = $this->transport->payloads()[0];
        $this->assertSame('from a Laravel app', $payload['content']['values'][0]['value']);
        $this->assertSame(__FILE__, $payload['origin']['file']);
        $this->assertSame($line, $payload['origin']['line']);
    }

    public function testQueryOriginPointsAtTheAppCodeNotTheFramework(): void
    {
        $line = __LINE__ + 1;
        DB::select('select 1 as one');

        $query = $this->payloadsOfType('query')[0];
        $this->assertSame(__FILE__, $query['origin']['file']);
        $this->assertSame($line, $query['origin']['line']);
    }

    public function testWatchersAreSingletons(): void
    {
        $this->assertSame($this->laravel()->make(QueryWatcher::class), $this->laravel()->make(QueryWatcher::class));
    }
}
