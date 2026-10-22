<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Protocol\Envelope;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Storage\SqliteCardStorage;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteCardStorageTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/peep-history-' . bin2hex(random_bytes(6)) . '/history.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->path) . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir(dirname($this->path))) {
            rmdir(dirname($this->path));
        }
    }

    public function testCardsSurviveARestart(): void
    {
        $store = new CardStore(storage: SqliteCardStorage::open($this->path));
        $store->apply(self::envelope('a', 'shop'), 1.0);
        $store->apply(self::envelope('b', 'shop', [['type' => 'label', 'content' => ['label' => 'Order'], 'origin' => null]]), 2.0);
        $store->apply(self::envelope('b', 'shop', [['type' => 'text', 'content' => ['text' => 'more'], 'origin' => null]]), 3.0);
        $store->apply(self::envelope('c', 'blog'), 4.0);
        $store->storage()->flush();

        $restarted = new CardStore(storage: SqliteCardStorage::open($this->path));

        $this->assertSame(3, $restarted->restore());
        $this->assertSame(['shop', 'blog'], $restarted->projects());
        $this->assertSame($store->find('shop', 'b')?->toArray(), $restarted->find('shop', 'b')?->toArray());
    }

    public function testRemovedClearedAndDroppedCardsAreGoneAfterARestart(): void
    {
        $store = new CardStore(maxCardsPerProject: 2, storage: SqliteCardStorage::open($this->path));
        $store->apply(self::envelope('a', 'shop'), 1.0);
        $store->apply(self::envelope('b', 'shop'), 2.0);
        $store->storage()->flush();
        $store->apply(self::envelope('c', 'shop'), 3.0);
        $store->apply(self::envelope('b', 'shop', [['type' => 'remove', 'content' => [], 'origin' => null]]), 4.0);
        $store->apply(self::envelope('x', 'blog'), 5.0);
        $store->clear('blog');
        $store->storage()->flush();

        $restarted = new CardStore(storage: SqliteCardStorage::open($this->path));
        $restarted->restore();

        $this->assertSame(['c'], array_map(static fn($card) => $card->id, $restarted->cards()));
    }

    public function testLoadKeepsTheNewestCardsOfEachProject(): void
    {
        $storage = SqliteCardStorage::open($this->path);
        $store = new CardStore(storage: $storage);
        foreach (range(1, 5) as $index) {
            $store->apply(self::envelope('card-' . $index), (float) $index);
        }
        $storage->flush();

        $restarted = new CardStore(maxCardsPerProject: 2, storage: SqliteCardStorage::open($this->path));
        $restarted->restore();

        $this->assertSame(['card-4', 'card-5'], array_map(static fn($card) => $card->id, $restarted->cards()));
    }

    /**
     * @param list<array<string, mixed>>|null $payloads
     */
    private static function envelope(string $card, string $project = 'shop', ?array $payloads = null): Envelope
    {
        return new EnvelopeDecoder()->decode((string) json_encode(EnvelopeDecoderTest::envelope($card, $project, $payloads)));
    }
}
