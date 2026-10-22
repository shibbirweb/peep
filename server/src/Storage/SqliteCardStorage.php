<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Storage;

use JsonException;
use PDO;
use PDOException;
use ShibbirWeb\PeepServer\Card\Card;

/**
 * Cards in a SQLite file, one row per card holding its JSON.
 *
 * Saves are buffered and written in one transaction by flush() (the application calls
 * it twice a second), so a burst of peep() calls costs one write, not one per call.
 */
final class SqliteCardStorage implements CardStorage
{
    public const string FILE_NAME = 'history.sqlite';

    /**
     * @var array<string, Card> "project\0id" => card, latest state
     */
    private array $pending = [];

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $path,
    ) {}

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /**
     * @throws PDOException When the file cannot be opened.
     */
    public static function open(string $path): self
    {
        $directory = dirname($path);
        if ($path !== ':memory:' && ! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cards (
            project TEXT NOT NULL,
            id TEXT NOT NULL,
            created_at REAL NOT NULL,
            data TEXT NOT NULL,
            PRIMARY KEY (project, id)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS cards_by_time ON cards (project, created_at)');

        if ($path !== ':memory:') {
            @chmod($path, 0600);
        }

        return new self($pdo, $path);
    }

    public function load(int $limitPerProject): array
    {
        $statement = $this->pdo->prepare('SELECT data FROM (
            SELECT data, created_at, ROW_NUMBER() OVER (PARTITION BY project ORDER BY created_at DESC) AS position FROM cards
        ) WHERE position <= :limit ORDER BY created_at');
        // Bound as an integer: SQLite compares a text parameter as greater than every number.
        $statement->bindValue('limit', $limitPerProject, PDO::PARAM_INT);
        $statement->execute();

        $cards = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            try {
                $data = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
            } catch (JsonException) {
                continue;
            }

            $card = is_array($data) ? Card::fromArray($data) : null;
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    public function save(Card $card): void
    {
        $this->pending[$card->project . "\0" . $card->id] = $card;
    }

    public function delete(string $project, string $id): void
    {
        unset($this->pending[$project . "\0" . $id]);

        $this->pdo->prepare('DELETE FROM cards WHERE project = ? AND id = ?')->execute([$project, $id]);
    }

    public function clear(?string $project): void
    {
        if ($project === null) {
            $this->pending = [];
            $this->pdo->exec('DELETE FROM cards');

            return;
        }

        $this->pending = array_filter($this->pending, static fn(Card $card): bool => $card->project !== $project);
        $this->pdo->prepare('DELETE FROM cards WHERE project = ?')->execute([$project]);
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $cards = $this->pending;
        $this->pending = [];

        $statement = $this->pdo->prepare('INSERT INTO cards (project, id, created_at, data) VALUES (?, ?, ?, ?)
            ON CONFLICT (project, id) DO UPDATE SET data = excluded.data');

        $this->pdo->beginTransaction();

        try {
            foreach ($cards as $card) {
                $statement->execute([
                    $card->project,
                    $card->id,
                    $card->createdAt,
                    json_encode($card->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                ]);
            }

            $this->pdo->commit();
        } catch (PDOException | JsonException $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }

    public function describe(): string
    {
        return 'SQLite (' . $this->path . ')';
    }
}
