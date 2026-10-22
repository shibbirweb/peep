<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\DB;

final class QueryWatcherTest extends LaravelTestCase
{
    protected function peepConfig(): array
    {
        return ['send_queries' => false, 'send_slow_queries' => false, 'send_duplicate_queries' => false, 'send_logs' => false];
    }

    public function testQueriesAreOffUntilShown(): void
    {
        DB::select('select 1');
        $this->assertSame([], $this->payloadsOfType('query'));

        peep()->showQueries();
        DB::select('select ? as value', [42]);
        peep()->stopShowingQueries();
        DB::select('select 3');

        $queries = $this->payloadsOfType('query');
        $this->assertCount(1, $queries);
        $this->assertSame('select ? as value', $queries[0]['content']['sql']);
        $this->assertSame([42], $queries[0]['content']['bindings']);
        $this->assertSame('select', $queries[0]['content']['type']);
        $this->assertSame('sqlite', $queries[0]['content']['driver']);
        $this->assertSame('testing', $queries[0]['content']['connection']);
    }

    public function testShowQueriesForACallbackOnly(): void
    {
        $result = peep()->showQueries(function () {
            DB::select('select 1');

            return 'done';
        });
        DB::select('select 2');

        $this->assertSame('done', $result);
        $this->assertCount(1, $this->payloadsOfType('query'));
    }

    public function testTypeFilter(): void
    {
        DB::statement('create table items (id integer primary key, name text)');

        peep()->showInsertQueries(function () {
            DB::insert('insert into items (name) values (?)', ['a']);
            DB::select('select * from items');
        });

        $queries = $this->payloadsOfType('query');
        $this->assertCount(1, $queries);
        $this->assertSame('insert', $queries[0]['content']['type']);
    }

    public function testSlowQueries(): void
    {
        peep()->showSlowQueries(0, function () {
            DB::select('select 1');
        });

        $query = $this->payloadsOfType('query')[0];
        $this->assertTrue($query['content']['slow']);
        $this->assertSame(['color' => 'orange'], $this->payloadsOfType('color')[0]['content']);
    }

    public function testDuplicateQueriesUpdateOneCard(): void
    {
        peep()->showDuplicateQueries(function () {
            for ($i = 0; $i < 3; $i++) {
                DB::select('select ? as value', [1]);
            }
            DB::select('select ? as value', [2]);
        });

        $requests = $this->requestsOfType('query');
        $this->assertCount(2, $requests, 'The first run is not a duplicate.');
        $this->assertSame(2, $requests[0]['payloads'][0]['content']['duplicate_count']);
        $this->assertSame(3, $requests[1]['payloads'][0]['content']['duplicate_count']);
        $this->assertSame($requests[0]['card'], $requests[1]['card']);
    }

    public function testConditionalQueries(): void
    {
        peep()->showConditionalQueries(function ($query) {
            return strpos($query->sql, 'users') !== false;
        }, function () {
            DB::select('select 1');
            DB::select("select 'users'");
        });

        $this->assertCount(1, $this->payloadsOfType('query'));
    }

    public function testCountQueries(): void
    {
        $result = peep()->countQueries(function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'counted';
        });

        $this->assertSame('counted', $result);
        $this->assertSame(2, $this->payloadsOfType('query_count')[0]['content']['count']);
    }

    public function testTheViewerSwitchTurnsQueriesOnAndOffAtRuntime(): void
    {
        $this->transport->postResponseBody = '{"accepted":true,"switches":{"queries":"on"},"switches_ttl":60}';
        peep('any call brings the switches');
        DB::select('select 1');
        $this->assertCount(1, $this->payloadsOfType('query'));

        $this->transport->postResponseBody = '{"accepted":true,"switches":{"queries":"off"},"switches_ttl":60}';
        peep('switch off');
        DB::select('select 2');
        $this->assertCount(1, $this->payloadsOfType('query'));

        peep()->showQueries();
        DB::select('select 3');
        $this->assertCount(2, $this->payloadsOfType('query'), 'Code wins over the viewer switch.');
    }
}
