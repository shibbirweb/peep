<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Card;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Protocol\Envelope;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

final class CardStoreTest extends TestCase
{
    public function testFirstEnvelopeCreatesACard(): void
    {
        $store = new CardStore();

        $events = $store->apply(self::envelope(), 10.0);

        $this->assertSame(['card.created'], self::names($events));
        $card = $store->find('shop', 'card-1');
        $this->assertNotNull($card);
        $this->assertSame('red', $card->color);
        $this->assertSame(['file' => '/app/a.php', 'line' => 3, 'function' => 'store', 'class' => null], $card->origin());
        $this->assertCount(1, $card->toArray()['payloads'], 'Update payloads are not content.');
    }

    public function testLaterEnvelopesUpdateTheSameCard(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope(), 10.0);

        $events = $store->apply(self::envelope(payloads: [
            ['type' => 'label', 'content' => ['label' => 'User'], 'origin' => null],
            ['type' => 'size', 'content' => ['size' => 'large'], 'origin' => null],
            ['type' => 'text', 'content' => ['text' => 'more'], 'origin' => ['file' => '/app/b.php', 'line' => 9]],
        ]), 11.0);

        $this->assertSame(['card.updated'], self::names($events));
        $card = $store->find('shop', 'card-1');
        $this->assertNotNull($card);
        $this->assertSame('User', $card->label);
        $this->assertSame('large', $card->size);
        $this->assertSame(11.0, $card->updatedAt);
        $this->assertCount(2, $card->toArray()['payloads']);
        $this->assertSame('/app/a.php', $card->origin()['file'] ?? null, 'The origin stays where the card was created.');
    }

    public function testReplaceSwapsTheContent(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope(), 10.0);

        $store->apply(self::envelope(payloads: [
            ['type' => 'replace', 'content' => ['values' => [['type' => 'int', 'value' => 2]]], 'origin' => null],
        ]), 11.0);

        $payloads = $store->find('shop', 'card-1')?->toArray()['payloads'];
        $this->assertSame([['type' => 'log', 'content' => ['values' => [['type' => 'int', 'value' => 2]]], 'origin' => ['file' => '/app/a.php', 'line' => 3, 'function' => 'store', 'class' => null]]], $payloads);
    }

    public function testRemove(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope(), 10.0);

        $events = $store->apply(self::envelope(payloads: [['type' => 'remove', 'content' => [], 'origin' => null]]), 11.0);

        $this->assertSame(['card.removed'], self::names($events));
        $this->assertNull($store->find('shop', 'card-1'));
        $this->assertSame([], $store->apply(self::envelope(payloads: [['type' => 'remove', 'content' => [], 'origin' => null]]), 12.0));
    }

    public function testProjectsAreKeptApartAndClearedSeparately(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope('a', 'shop'), 10.0);
        $store->apply(self::envelope('b', 'blog'), 11.0);

        $this->assertSame(['shop', 'blog'], $store->projects());
        $this->assertCount(2, $store->cards());
        $this->assertCount(1, $store->cards('blog'));

        $events = $store->clear('shop');
        $this->assertSame(['project' => 'shop'], $events[0]->data);
        $this->assertSame(['blog'], $store->projects());

        $store->clear();
        $this->assertSame([], $store->cards());
    }

    public function testCardsAreReturnedOldestFirstWithALimit(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope('b'), 20.0);
        $store->apply(self::envelope('a'), 10.0);
        $store->apply(self::envelope('c'), 30.0);

        $ids = array_map(static fn($card) => $card->id, $store->cards(limit: 2));

        $this->assertSame(['b', 'c'], $ids);
    }

    public function testOldestCardsAreDroppedWhenAProjectIsFull(): void
    {
        $store = new CardStore(maxCardsPerProject: 2);
        $store->apply(self::envelope('first'), 1.0);
        $store->apply(self::envelope('second'), 2.0);

        $events = $store->apply(self::envelope('third'), 3.0);

        $this->assertSame(['card.removed', 'card.created'], self::names($events));
        $this->assertSame(['project' => 'shop', 'id' => 'first'], $events[0]->data);
        $this->assertNull($store->find('shop', 'first'));
    }

    public function testHideAndExpandAreCardUpdates(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope(), 10.0);

        $store->apply(self::envelope(payloads: [
            ['type' => 'hide', 'content' => [], 'origin' => null],
            ['type' => 'expand', 'content' => ['level' => 2, 'keys' => ['user'], 'all' => false], 'origin' => null],
        ]), 11.0);

        $card = $store->find('shop', 'card-1')?->toArray();
        $this->assertNotNull($card);
        $this->assertTrue($card['hidden']);
        $this->assertSame(['level' => 2, 'keys' => ['user'], 'all' => false], $card['expand']);
        $this->assertCount(1, $card['payloads']);
    }

    public function testCountReplacesThePreviousCount(): void
    {
        $store = new CardStore();
        $count = static fn(int $value): array => [['type' => 'count', 'content' => ['name' => 'loop', 'count' => $value], 'origin' => ['file' => '/app/a.php', 'line' => 1]]];

        $store->apply(self::envelope('counter', payloads: $count(1)), 1.0);
        $store->apply(self::envelope('counter', payloads: $count(2)), 2.0);

        $payloads = $store->find('shop', 'counter')?->toArray()['payloads'] ?? [];
        $this->assertCount(1, $payloads);
        $this->assertSame(2, $payloads[0]['content']['count']);
    }

    public function testScreenCommands(): void
    {
        $store = new CardStore();
        $store->apply(self::envelope('a', 'shop'), 1.0);
        $store->apply(self::envelope('b', 'blog'), 2.0);

        $events = $store->apply(self::envelope('c', 'shop', [['type' => 'clear_screen', 'content' => [], 'origin' => null]]), 3.0);
        $this->assertSame(['screen.cleared'], self::names($events));
        $this->assertSame(['blog'], $store->projects());
        $this->assertNull($store->find('shop', 'c'), 'A command creates no card.');

        $events = $store->apply(self::envelope('d', 'blog', [
            ['type' => 'new_screen', 'content' => ['title' => 'Checkout'], 'origin' => ['file' => '/app/a.php', 'line' => 1]],
        ]), 4.0);
        $this->assertSame(['screen.cleared', 'card.created'], self::names($events));
        $this->assertSame(['d'], array_map(static fn($card) => $card->id, $store->cards('blog')));

        $events = $store->apply(self::envelope('e', 'shop', [['type' => 'clear_all', 'content' => [], 'origin' => null]]), 5.0);
        $this->assertSame(['project' => null], $events[0]->data);
        $this->assertSame([], $store->cards());

        $events = $store->apply(self::envelope('f', 'shop', [['type' => 'show_app', 'content' => [], 'origin' => null]]), 6.0);
        $this->assertSame(['app.command'], self::names($events));
    }

    /**
     * @param list<array<string, mixed>>|null $payloads
     */
    private static function envelope(string $card = 'card-1', string $project = 'shop', ?array $payloads = null): Envelope
    {
        return new EnvelopeDecoder()->decode((string) json_encode(EnvelopeDecoderTest::envelope($card, $project, $payloads)));
    }

    /**
     * @param list<ServerEvent> $events
     * @return list<string>
     */
    private static function names(array $events): array
    {
        return array_map(static fn(ServerEvent $event): string => $event->name, $events);
    }
}
