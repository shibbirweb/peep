<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use ShibbirWeb\Peep\Tests\Laravel\Fixture\OrderShipped;
use ShibbirWeb\Peep\Tests\Laravel\Fixture\SendInvoice;

final class WatchersTest extends LaravelTestCase
{
    protected function peepConfig(): array
    {
        return ['dumps_passthrough' => false];
    }

    public function testEvents(): void
    {
        peep()->showEvents(function () {
            Event::dispatch(new OrderShipped(7));
            Event::dispatch('orders.archived', [['id' => 7]]);
            Event::dispatch('eloquent.saved: App\\User', []);
        });

        $events = $this->payloadsOfType('event');
        $this->assertCount(2, $events, 'Ignored framework events are not shown.');
        $this->assertSame(OrderShipped::class, $events[0]['content']['name']);
        $this->assertSame('orders.archived', $events[1]['content']['name']);
    }

    public function testJobsAndTheirContext(): void
    {
        peep()->showJobs();

        dispatch(new SendInvoice());

        $jobs = $this->payloadsOfType('job');
        $statuses = [];
        foreach ($jobs as $job) {
            $statuses[] = $job['content']['status'];
        }
        $this->assertContains('processing', $statuses);
        $this->assertContains('processed', $statuses);
        $this->assertSame(SendInvoice::class, $jobs[count($jobs) - 1]['content']['name']);

        foreach ($this->transport->requests as $request) {
            if ($request['body']['payloads'][0]['type'] === 'log' && $request['body']['payloads'][0]['content']['values'][0]['value'] === 'inside the job') {
                $this->assertSame('job', $request['body']['meta']['request']['kind']);
                $this->assertSame(SendInvoice::class, $request['body']['meta']['request']['label']);

                return;
            }
        }

        $this->fail('The peep() inside the job was not sent.');
    }

    public function testCache(): void
    {
        peep()->showCache(function () {
            Cache::put('greeting', 'hello', 60);
            Cache::get('greeting');
            Cache::get('missing');
            Cache::forget('greeting');
        });

        $actions = [];
        foreach ($this->payloadsOfType('cache') as $payload) {
            $actions[] = $payload['content']['action'];
        }

        $this->assertSame(['written', 'hit', 'missed', 'forgotten'], $actions);
    }

    public function testLogsAndReportedExceptions(): void
    {
        Log::warning('Disk almost full', ['free' => '2%']);
        report(new RuntimeException('Payment failed'));

        $logs = $this->payloadsOfType('log_message');
        $this->assertSame('warning', $logs[0]['content']['level']);
        $this->assertSame('Disk almost full', $logs[0]['content']['message']);
        $this->assertCount(1, $logs, 'The reported exception is shown by the exception watcher, not as a log.');

        $exceptions = $this->payloadsOfType('exception');
        $this->assertSame('Payment failed', $exceptions[0]['content']['message']);
    }

    public function testMailPreview(): void
    {
        Mail::raw('Your invoice is ready.', function ($message) {
            $message->to('ada@example.com')->subject('Invoice');
        });

        $mail = $this->payloadsOfType('mail_preview');
        $this->assertCount(1, $mail);
        $this->assertSame('Invoice', $mail[0]['content']['subject']);
        $this->assertTrue(strpos(implode(',', $mail[0]['content']['to']), 'ada@example.com') !== false);
    }

    public function testDumps(): void
    {
        dump(['dumped' => true]);

        $dumps = $this->payloadsOfType('log');
        $this->assertSame('dumped', $dumps[0]['content']['values'][0]['children'][0]['key']);
    }

    public function testHttpClient(): void
    {
        if (! class_exists('Illuminate\\Http\\Client\\Events\\RequestSending')) {
            $this->markTestSkipped('HTTP client events need Laravel 8.');
        }

        $http = 'Illuminate\\Support\\Facades\\Http';
        $http::fake(['example.com/*' => $http::response(['ok' => true], 201)]);

        peep()->showHttpClientRequests(function () use ($http) {
            $http::withHeaders(['Authorization' => 'Bearer secret'])->post('https://example.com/orders', ['id' => 7]);
        });

        $requests = $this->requestsOfType('http');
        $last = $requests[count($requests) - 1]['payloads'][0]['content'];
        $this->assertSame('POST', $last['method']);
        $this->assertSame(201, $last['status']);
        $this->assertSame('***', $last['headers']['Authorization']);
    }

    public function testViews(): void
    {
        $directory = sys_get_temp_dir() . '/peep-views-' . getmypid();
        @mkdir($directory);
        file_put_contents($directory . '/greeting.blade.php', 'Hello {{ $name }}');
        $this->laravel()->make('view')->addLocation($directory);

        peep()->showViews(function () {
            view('greeting', ['name' => 'Ada'])->render();
        });

        $views = $this->payloadsOfType('view');
        $this->assertSame('greeting', $views[0]['content']['name']);
        $this->assertSame('name', $views[0]['content']['data']['children'][0]['key']);
    }
}
