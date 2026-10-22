<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProductionTest extends LaravelTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'production';
        $app['config']->set('peep.enabled', null);
        $app['config']->set('peep.send_queries', true);
    }

    public function testNothingIsSentInProductionByDefault(): void
    {
        DB::select('select 1');
        Log::info('hello');
        peep('not sent');

        $this->assertSame([], $this->transport->requests);
        $this->assertTrue(peep()->disabled());
    }
}
