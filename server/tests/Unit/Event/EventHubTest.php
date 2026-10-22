<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;

use function Amp\delay;

final class EventHubTest extends TestCase
{
    public function testNumbersEventsAndFormatsThemAsSse(): void
    {
        $hub = new EventHub();
        $hub->publish([new ServerEvent('card.created', ['id' => 'a']), new ServerEvent('card.updated', ['id' => 'a'])]);

        $this->assertSame(2, $hub->lastId());
        $events = $hub->since(0);
        $this->assertNotNull($events);
        $this->assertSame([1, 2], array_map(static fn(ServerEvent $event): int => $event->id, $events));
        $this->assertSame("id: 1\nevent: card.created\ndata: {\"id\":\"a\"}\n\n", $events[0]->toSse());
    }

    public function testSinceReturnsOnlyMissedEventsOrNullWhenTooOld(): void
    {
        $hub = new EventHub(historySize: 2);
        $hub->publish([new ServerEvent('a', []), new ServerEvent('b', []), new ServerEvent('c', [])]);

        $this->assertSame([], $hub->since(3));
        $this->assertSame(['c'], array_map(static fn(ServerEvent $event): string => $event->name, $hub->since(2) ?? []));
        $this->assertNull($hub->since(0), 'Event 1 is no longer in the history.');
    }

    public function testSubscribersReceiveEventsUntilUnsubscribed(): void
    {
        $hub = new EventHub();
        [$id, $queue] = $hub->subscribe();
        $iterator = $queue->iterate();

        $hub->publish([new ServerEvent('card.created', ['id' => 'a'])]);

        $this->assertTrue($iterator->continue());
        $chunk = $iterator->getValue();
        $this->assertIsString($chunk);
        $this->assertStringContainsString('event: card.created', $chunk);

        $hub->unsubscribe($id);
        delay(0);
        $this->assertSame(0, $hub->subscriberCount());
        $this->assertTrue($queue->isComplete());
    }

    public function testCloseAllEndsEveryStream(): void
    {
        $hub = new EventHub();
        [, $first] = $hub->subscribe();
        [, $second] = $hub->subscribe();

        $hub->closeAll();

        $this->assertSame(0, $hub->subscriberCount());
        $this->assertTrue($first->isComplete());
        $this->assertTrue($second->isComplete());
    }
}
