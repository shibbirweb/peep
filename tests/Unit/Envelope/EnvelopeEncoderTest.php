<?php

namespace ShibbirWeb\Peep\Tests\Unit\Envelope;

use ShibbirWeb\Peep\Envelope\Envelope;
use ShibbirWeb\Peep\Envelope\EnvelopeEncoder;
use ShibbirWeb\Peep\Origin\Origin;
use ShibbirWeb\Peep\Payload\ColorPayload;
use ShibbirWeb\Peep\Payload\TextPayload;
use ShibbirWeb\Peep\Tests\TestCase;

final class EnvelopeEncoderTest extends TestCase
{
    public function testEncodesTheProtocolShape(): void
    {
        $envelope = new Envelope(
            'card-1',
            [new TextPayload('a/b é', new Origin('/app/a.php', 3)), new ColorPayload('red')],
            ['project' => 'shop', 'sent_at' => 1.0]
        );

        $json = (new EnvelopeEncoder())->encode($envelope);

        $this->assertIsString($json);
        $this->assertStringContainsString('"text":"a/b é"', $json);
        $this->assertStringContainsString('"sent_at":1.0', $json);

        $decoded = json_decode($json, true);
        $this->assertSame(1, $decoded['protocol']);
        $this->assertSame('card-1', $decoded['card']);
        $this->assertSame('text', $decoded['payloads'][0]['type']);
        $this->assertSame(['file' => '/app/a.php', 'line' => 3, 'function' => null, 'class' => null], $decoded['payloads'][0]['origin']);
        $this->assertSame(['type' => 'color', 'content' => ['color' => 'red'], 'origin' => null], $decoded['payloads'][1]);
        $this->assertSame(['project' => 'shop', 'sent_at' => 1.0], $decoded['meta']);
    }

    public function testInvalidUtf8DoesNotStopTheEnvelope(): void
    {
        $envelope = new Envelope('card-1', [new TextPayload("bad \xff byte")], []);

        $json = (new EnvelopeEncoder())->encode($envelope);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertStringStartsWith('bad ', $decoded['payloads'][0]['content']['text']);
        $this->assertStringEndsWith(' byte', $decoded['payloads'][0]['content']['text']);
    }
}
