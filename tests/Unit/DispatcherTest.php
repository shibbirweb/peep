<?php

namespace ShibbirWeb\Peep\Tests\Unit;

use LogicException;
use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Context\ExecutionContext;
use ShibbirWeb\Peep\Dispatcher;
use ShibbirWeb\Peep\Envelope\EnvelopeEncoder;
use ShibbirWeb\Peep\Envelope\MetaFactory;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Payload\TextPayload;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\AvailabilityCache;
use ShibbirWeb\Peep\Version;

final class DispatcherTest extends TestCase
{
    /**
     * @var RecordingTransport
     */
    private $transport;

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
    }

    protected function tear_down()
    {
        AvailabilityCache::reset();

        parent::tear_down();
    }

    public function testSendsAnEnvelopeWithMeta(): void
    {
        $this->dispatcher(true)->dispatch('card-1', [new TextPayload('hi')]);

        $this->assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        $this->assertSame('/_peep/envelope', $request['path']);
        $this->assertSame(1, $request['body']['protocol']);
        $this->assertSame('card-1', $request['body']['card']);

        $meta = $request['body']['meta'];
        $this->assertSame('shop', $meta['project']);
        $this->assertSame(PHP_VERSION, $meta['php_version']);
        $this->assertSame(Version::VERSION, $meta['peep_version']);
        $this->assertSame('cli', $meta['request']['kind']);
        $this->assertIsString($meta['hostname']);
        $this->assertIsFloat($meta['sent_at']);
    }

    public function testCardsOfOneProcessShareTheRequestId(): void
    {
        $dispatcher = $this->dispatcher(true);
        $dispatcher->dispatch('card-1', [new TextPayload('a')]);
        $dispatcher->dispatch('card-2', [new TextPayload('b')]);

        $this->assertSame(
            $this->transport->requests[0]['body']['meta']['request']['id'],
            $this->transport->requests[1]['body']['meta']['request']['id']
        );
    }

    public function testSendsNothingWhenDisabled(): void
    {
        $dispatcher = $this->dispatcher(false);
        $dispatcher->dispatch('card-1', [new TextPayload('hi')]);
        $this->assertCount(0, $this->transport->requests);

        $dispatcher->enable();
        $dispatcher->dispatch('card-1', [new TextPayload('hi')]);
        $this->assertCount(1, $this->transport->requests);

        $dispatcher->disable();
        $dispatcher->dispatch('card-1', [new TextPayload('hi')]);
        $this->assertCount(1, $this->transport->requests);
    }

    public function testUnreachableViewerIsSkippedAfterwards(): void
    {
        $dispatcher = $this->dispatcher(true);

        $this->transport->failWith = new TransportException('refused');
        $dispatcher->dispatch('card-1', [new TextPayload('first')]);

        $this->transport->failWith = null;
        $dispatcher->dispatch('card-1', [new TextPayload('second')]);

        $this->assertCount(0, $this->transport->requests);
    }

    public function testUnexpectedErrorsNeverReachTheApp(): void
    {
        $dispatcher = $this->dispatcher(true);
        $this->transport->failWith = new LogicException('bug inside Peep');

        $dispatcher->dispatch('card-1', [new TextPayload('hi')]);

        $this->transport->failWith = null;
        $dispatcher->dispatch('card-1', [new TextPayload('again')]);
        $this->assertCount(1, $this->transport->requests, 'A bug is not a down viewer.');
    }

    public function testWarningsNeverReachTheAppErrorHandler(): void
    {
        $this->transport->raiseWarning = true;

        // The base TestCase turns any warning that escapes into a failing exception.
        $this->dispatcher(true)->dispatch('card-1', [new TextPayload('hi')]);

        $this->assertCount(1, $this->transport->requests);
    }

    private function dispatcher(bool $enabled): Dispatcher
    {
        $config = Config::fromArray(['project' => 'shop']);

        return new Dispatcher(
            $enabled,
            $config->endpointKey(),
            $this->transport,
            new AvailabilityCache(30, false),
            new EnvelopeEncoder(),
            new MetaFactory($config, new ExecutionContext())
        );
    }
}
