<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Agent\CardQuery;
use ShibbirWeb\PeepServer\Agent\TextRenderer;
use ShibbirWeb\PeepServer\Card\Card;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

final class CardQueryTest extends TestCase
{
    public function testSinceTakesUnixTimeOrAnAge(): void
    {
        $this->assertSame(1000.5, CardQuery::since('1000.5', 2000.0));
        $this->assertSame(1910.0, CardQuery::since('90s', 2000.0));
        $this->assertSame(1700.0, CardQuery::since('5m', 2000.0));
        $this->assertSame(-5200.0, CardQuery::since('2h', 2000.0));
        $this->assertNull(CardQuery::since('yesterday', 2000.0));
        $this->assertNull(CardQuery::since(null, 2000.0));
    }

    public function testTheQueryStringIsReadSafely(): void
    {
        $query = CardQuery::fromQueryString('project=shop&since=1m&request=REQ-1&limit=5000&search=+order+&base=/app', 120.0);

        $this->assertSame('shop', $query->project);
        $this->assertSame(60.0, $query->since);
        $this->assertSame('req1', $query->request, 'Ids are compared without dashes and case.');
        $this->assertSame(CardQuery::MAX_LIMIT, $query->limit);
        $this->assertSame('order', $query->search);
        $this->assertSame('/app', $query->base);

        $this->assertSame(CardQuery::DEFAULT_LIMIT, CardQuery::fromQueryString('limit=abc&project=', 0.0)->limit);
        $this->assertNull(CardQuery::fromQueryString('project=', 0.0)->project);
    }

    public function testCardsAreFilteredAndTheNewestKept(): void
    {
        $store = new CardStore();
        foreach (['a' => 10.0, 'b' => 20.0, 'c' => 30.0] as $id => $time) {
            $store->apply(new EnvelopeDecoder()->decode((string) json_encode(EnvelopeDecoderTest::envelope('card-' . $id, 'shop', [
                ['type' => 'text', 'content' => ['text' => 'step ' . $id], 'origin' => ['file' => '/app/a.php', 'line' => 1]],
            ]))), $time);
        }
        $cards = $store->cards('shop');
        $renderer = new TextRenderer(40.0);
        $ids = static fn(array $cards): array => array_map(static fn(Card $card): string => $card->id, $cards);

        $this->assertSame(['card-b', 'card-c'], $ids(new CardQuery(since: 15.0)->cards($cards, $renderer)));
        $this->assertSame(['card-c'], $ids(new CardQuery(limit: 1)->cards($cards, $renderer)));
        $this->assertSame(['card-a'], $ids(new CardQuery(search: 'STEP A')->cards($cards, $renderer)));
        $this->assertSame(['card-b'], $ids(new CardQuery(card: 'cardb')->cards($cards, $renderer)));
        $this->assertSame([], $ids(new CardQuery(request: 'other')->cards($cards, $renderer)));
    }
}
