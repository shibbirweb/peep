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
