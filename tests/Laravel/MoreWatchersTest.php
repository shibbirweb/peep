<?php

namespace ShibbirWeb\Peep\Tests\Laravel;

use ShibbirWeb\Peep\Tests\Laravel\Fixture\InvoiceMail;

final class MoreWatchersTest extends LaravelTestCase
{
    protected function peepConfig(): array
    {
        return ['send_requests' => true, 'send_deprecations' => true];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->make('router')->get('/peep-test/{id}', function ($id) {
            return response()->json(['id' => (int) $id]);
        });

        $directory = sys_get_temp_dir() . '/peep-mail-views-' . getmypid();
        if (! is_dir($directory)) {
            mkdir($directory);
        }
        file_put_contents($directory . '/peep-invoice-mail.blade.php', '<p>Invoice {{ $number }}</p>');
        $app->make('view')->addLocation($directory);
    }

    public function testRequestsAreShownAfterTheyAreHandled(): void
    {
        $this->get('/peep-test/7')->assertStatus(200);

        $request = $this->payloadsOfType('request')[0]['content'];
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/peep-test/7', $request['uri']);
        $this->assertSame(200, $request['status']);
    }

    public function testTestResponseMacro(): void
    {
        $response = $this->get('/peep-test/9');

        // Same as $response->peep(), spelled out so static analysis sees the macro call.
        $this->assertSame($response, $response->__call('peep', []));

        $labels = $this->payloadsOfType('label');
        $this->assertSame('Test response', $labels[count($labels) - 1]['content']['label']);
    }

    public function testDeprecationsAreShown(): void
    {
        // Silenced so Laravel's own handler (which turns notices into exceptions on 5.x to 8) ignores it.
        @trigger_error('Old helper is deprecated', E_USER_DEPRECATED);

        $logs = $this->payloadsOfType('log_message');
        $this->assertSame('deprecation', $logs[0]['content']['level']);
        $this->assertSame('Old helper is deprecated', $logs[0]['content']['message']);
    }

    public function testMailable(): void
    {
        peep()->mailable(new InvoiceMail());

        $this->assertTrue(strpos($this->payloadsOfType('html')[0]['content']['html'], 'Invoice INV-7') !== false);
        $this->assertSame('Mailable: Invoice ready', $this->payloadsOfType('label')[0]['content']['label']);
    }

    public function testContext(): void
    {
        $context = 'Illuminate\\Support\\Facades\\Context';
        if (! class_exists($context)) {
            $this->markTestSkipped('Context needs Laravel 11.');
        }

        $context::add('tenant', 'acme');
        peep()->context('tenant');

        $this->assertSame('tenant', $this->payloadsOfType('log')[0]['content']['values'][0]['children'][0]['key']);
    }
}
