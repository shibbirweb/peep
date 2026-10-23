<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Protocol\InvalidEnvelopeException;

final class EnvelopeDecoderTest extends TestCase
{
    public function testDecodesAValidEnvelope(): void
    {
        $envelope = new EnvelopeDecoder()->decode((string) json_encode(self::envelope()));

        $this->assertSame('card-1', $envelope->card);
        $this->assertSame('shop', $envelope->project);
        $this->assertSame('dev-laptop', $envelope->hostname);
        $this->assertSame('8.5.12', $envelope->phpVersion);
        $this->assertSame('req-1', $envelope->request['id']);
        $this->assertSame('http', $envelope->request['kind']);
        $this->assertSame('POST /users', $envelope->request['label']);
        $this->assertCount(2, $envelope->payloads);
        $this->assertSame('log', $envelope->payloads[0]->type);
        $this->assertSame(['file' => '/app/a.php', 'line' => 3, 'function' => 'store', 'class' => null], $envelope->payloads[0]->origin);
        $this->assertFalse($envelope->payloads[0]->isUpdate());
        $this->assertTrue($envelope->payloads[1]->isUpdate());
        $this->assertNull($envelope->payloads[1]->origin);
    }

    public function testReadsTheLogFolderAndIgnoresABadOne(): void
    {
        $decode = static fn(mixed $logs): ?array => new EnvelopeDecoder()->decode((string) json_encode(array_replace_recursive(self::envelope(), ['meta' => ['logs' => $logs]])))->logs;

        $this->assertNull(new EnvelopeDecoder()->decode((string) json_encode(self::envelope()))->logs);
        $this->assertSame(['path' => '/app/storage/logs', 'timezone' => 'Europe/Berlin'], $decode(['path' => '/app/storage/logs', 'timezone' => 'Europe/Berlin']));
        $this->assertSame(['path' => 'C:\\shop\\storage\\logs', 'timezone' => 'UTC'], $decode(['path' => 'C:\\shop\\storage\\logs', 'timezone' => 'Mars/Olympus']));
        $this->assertNull($decode(['path' => 'storage/logs']), 'A relative path means nothing to the viewer.');
        $this->assertNull($decode('/app/storage/logs'));
    }

    public function testIgnoresUnknownFields(): void
    {
        $data = self::envelope();
        $meta = $data['meta'];
        $this->assertIsArray($meta);
        $data['future_field'] = ['x' => 1];
        $data['meta'] = ['future_meta' => true, ...$meta];

        $this->assertSame('card-1', new EnvelopeDecoder()->decode((string) json_encode($data))->card);
    }

    public function testRejectsInvalidInput(): void
    {
        $cases = [
            'invalid_json' => '{not json',
            'unsupported_protocol' => (string) json_encode(['protocol' => 2] + self::envelope()),
            'invalid_envelope' => (string) json_encode(array_diff_key(self::envelope(), ['card' => true])),
            'invalid_payload' => (string) json_encode(['payloads' => [['type' => 'log']]] + self::envelope()),
        ];

        foreach ($cases as $expectedError => $json) {
            try {
                new EnvelopeDecoder()->decode($json);
                $this->fail("Expected {$expectedError}.");
            } catch (InvalidEnvelopeException $exception) {
                $this->assertSame($expectedError, $exception->error, $json);
            }
        }
    }

    public function testRejectsEmptyPayloads(): void
    {
        $this->expectException(InvalidEnvelopeException::class);

        new EnvelopeDecoder()->decode((string) json_encode(['payloads' => []] + self::envelope()));
    }

    /**
     * @param list<array<string, mixed>>|null $payloads
     * @return array<string, mixed>
     */
    public static function envelope(string $card = 'card-1', string $project = 'shop', ?array $payloads = null): array
    {
        return [
            'protocol' => 1,
            'card' => $card,
            'payloads' => $payloads ?? [
                [
                    'type' => 'log',
                    'content' => ['values' => [['type' => 'string', 'value' => 'hi', 'length' => 2]]],
                    'origin' => ['file' => '/app/a.php', 'line' => 3, 'function' => 'store', 'class' => null],
                ],
                ['type' => 'color', 'content' => ['color' => 'red'], 'origin' => null],
            ],
            'meta' => [
                'project' => $project,
                'hostname' => 'dev-laptop',
                'php_version' => '8.5.12',
                'peep_version' => '0.1.0',
                'request' => ['id' => 'req-1', 'kind' => 'http', 'label' => 'POST /users', 'started_at' => 1792663321.5],
                'sent_at' => 1792663321.7,
            ],
        ];
    }
}
