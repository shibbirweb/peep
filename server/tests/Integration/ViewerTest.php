<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Application;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

use function Amp\async;
use function Amp\delay;

/**
 * The viewer running in this process, spoken to over real HTTP.
 */
final class ViewerTest extends TestCase
{
    private Application $application;

    private HttpClient $client;

    protected function setUp(): void
    {
        $this->application = new Application('127.0.0.1', self::freePort(), new NullLogger());
        $this->application->start();
        $this->client = HttpClientBuilder::buildDefault();
    }

    protected function tearDown(): void
    {
        $this->application->stop();
    }

    public function testHealth(): void
    {
        $response = $this->client->request(new Request($this->application->url() . '/_peep/health'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['app' => 'peep', 'protocol' => 1, 'version' => '0.0.0'], json_decode($response->getBody()->buffer(), true));
    }

    public function testEnvelopesReachTheStreamLive(): void
    {
        $stream = $this->client->request(new Request($this->application->url() . '/_peep/stream'));
        $this->assertSame('text/event-stream; charset=utf-8', $stream->getHeader('content-type'));
        $body = $stream->getBody();

        $snapshot = $body->read();
        $this->assertStringContainsString("event: snapshot\n", (string) $snapshot);

        $post = new Request($this->application->url() . '/_peep/envelope', 'POST', (string) json_encode(EnvelopeDecoderTest::envelope()));
        $post->setHeader('content-type', 'application/json');
        $response = $this->client->request($post);

        $this->assertSame(202, $response->getStatus());
        $answer = json_decode($response->getBody()->buffer(), true);
        $this->assertIsArray($answer);
        $this->assertTrue($answer['accepted']);
        $this->assertSame(5, $answer['switches_ttl']);

        $event = '';
        while (! str_contains($event, "\n\n")) {
            $event .= (string) $body->read();
        }

        $this->assertStringContainsString("event: card.created\n", $event);
        $this->assertStringContainsString('"id":"card-1"', $event);
        $this->assertStringContainsString('"color":"red"', $event);
    }

    public function testInvalidEnvelopeIsRejected(): void
    {
        $response = $this->client->request(new Request($this->application->url() . '/_peep/envelope', 'POST', '{"protocol":2}'));

        $this->assertSame(400, $response->getStatus());
        $answer = json_decode($response->getBody()->buffer(), true);
        $this->assertIsArray($answer);
        $this->assertSame('unsupported_protocol', $answer['error'] ?? null);
    }

    public function testClearNeedsTheUiHeaderAndClearsCards(): void
    {
        $post = new Request($this->application->url() . '/_peep/envelope', 'POST', (string) json_encode(EnvelopeDecoderTest::envelope()));
        $this->client->request($post)->getBody()->buffer();
        $this->assertCount(1, $this->application->cards->cards());

        $withoutHeader = $this->client->request(new Request($this->application->url() . '/_peep/ui/clear', 'POST', '{}'));
        $this->assertSame(403, $withoutHeader->getStatus());

        $clear = new Request($this->application->url() . '/_peep/ui/clear', 'POST', '{"project":"shop"}');
        $clear->setHeader('x-peep-ui', '1');
        $this->assertSame(200, $this->client->request($clear)->getStatus());
        $this->assertSame([], $this->application->cards->cards());
    }

    public function testSwitchesAreSentToClients(): void
    {
        $this->application->switches->update('shop', ['queries' => 'on']);

        $answer = $this->json('/_peep/switches?project=shop');
        $this->assertSame(['switches' => ['queries' => 'on', 'slow_queries' => 'default', 'duplicate_queries' => 'default', 'slow_query_ms' => null], 'switches_ttl' => 5], $answer);

        $post = new Request($this->application->url() . '/_peep/envelope', 'POST', (string) json_encode(EnvelopeDecoderTest::envelope()));
        $envelopeAnswer = json_decode($this->client->request($post)->getBody()->buffer(), true);
        $this->assertIsArray($envelopeAnswer);
        $this->assertSame($answer['switches'], $envelopeAnswer['switches'] ?? null);
    }

    public function testPauseLockFlow(): void
    {
        $lockPayload = [['type' => 'lock', 'content' => ['name' => 'lock-1'], 'origin' => ['file' => '/app/a.php', 'line' => 3]]];
        $post = new Request($this->application->url() . '/_peep/envelope', 'POST', (string) json_encode(EnvelopeDecoderTest::envelope(payloads: $lockPayload)));
        $this->client->request($post)->getBody()->buffer();

        $this->assertSame(['active' => true, 'stop' => false], $this->json('/_peep/locks/lock-1'));

        $withoutHeader = $this->client->request(new Request($this->application->url() . '/_peep/ui/locks/lock-1/stop', 'POST'));
        $this->assertSame(403, $withoutHeader->getStatus());

        $stop = new Request($this->application->url() . '/_peep/ui/locks/lock-1/stop', 'POST');
        $stop->setHeader('x-peep-ui', '1');
        $this->assertSame(200, $this->client->request($stop)->getStatus());

        $this->assertSame(['active' => false, 'stop' => true], $this->json('/_peep/locks/lock-1'));
        $this->assertSame(404, $this->client->request(new Request($this->application->url() . '/_peep/locks/unknown'))->getStatus());
    }

    public function testTheToolbarChangesSwitchesForRunningApps(): void
    {
        $answer = $this->ui('/_peep/ui/switches', ['project' => 'shop', 'switches' => ['queries' => 'off', 'slow_query_ms' => 50]]);

        $this->assertSame('off', self::at($answer, 'switches', 'queries'));
        $this->assertSame(['queries' => 'off', 'slow_queries' => 'default', 'duplicate_queries' => 'default', 'slow_query_ms' => 50], $this->json('/_peep/switches?project=shop')['switches'] ?? null);
        $this->assertSame($this->application->switches->all(), $this->application->settings->settings->switches, 'Saved with the settings.');
    }

    public function testPausedReceivingIgnoresEnvelopes(): void
    {
        $this->ui('/_peep/ui/receiving', ['paused' => true]);

        $post = fn(): Request => new Request($this->application->url() . '/_peep/envelope', 'POST', (string) json_encode(EnvelopeDecoderTest::envelope()));
        $answer = json_decode($this->client->request($post())->getBody()->buffer(), true);

        $this->assertIsArray($answer);
        $this->assertFalse($answer['accepted']);
        $this->assertSame([], $this->application->cards->cards());

        $this->ui('/_peep/ui/receiving', ['paused' => false]);
        $this->client->request($post())->getBody()->buffer();
        $this->assertCount(1, $this->application->cards->cards());
    }

    public function testNewScreenArchivesAndArchivesCanBeOpened(): void
    {
        $post = new Request($this->application->url() . '/_peep/envelope', 'POST', (string) json_encode(EnvelopeDecoderTest::envelope()));
        $this->client->request($post)->getBody()->buffer();

        $this->ui('/_peep/ui/screens', ['project' => 'shop']);
        $screens = $this->application->cards->screens('shop');
        $this->assertCount(1, $screens);

        $screen = $this->json('/_peep/ui/screens/' . $screens[0]['id']);
        $this->assertSame('card-1', self::at($screen ?? [], 'cards', 0, 'id'));
        $this->assertSame(404, $this->client->request(new Request($this->application->url() . '/_peep/ui/screens/nope'))->getStatus());
    }

    public function testSettingsAreValidatedAndReportWhatNeedsARestart(): void
    {
        $page = $this->ui('/_peep/ui/settings', ['changes' => ['theme' => 'dark', 'port' => 9999]]);
        $this->assertSame('dark', self::at($page, 'settings', 'theme'));
        $this->assertSame(['port'], $page['restart_required'] ?? null);

        $invalid = new Request($this->application->url() . '/_peep/ui/settings', 'POST', (string) json_encode(['changes' => ['ide' => 'notepad']]));
        $invalid->setHeader('x-peep-ui', '1');
        $response = $this->client->request($invalid);
        $this->assertSame(422, $response->getStatus());
        $this->assertStringContainsString('"setting":"ide"', $response->getBody()->buffer());

        $this->assertSame('dark', self::at($this->json('/_peep/ui/settings') ?? [], 'settings', 'theme'));
    }

    /**
     * A value deep inside decoded JSON, or null.
     *
     * @param array<mixed> $data
     */
    private static function at(array $data, string|int ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * POST to a UI action, as the viewer's own page does.
     *
     * @param array<string, mixed> $data
     * @return array<mixed>
     */
    private function ui(string $path, array $data): array
    {
        $request = new Request($this->application->url() . $path, 'POST', (string) json_encode($data));
        $request->setHeader('x-peep-ui', '1');
        $response = $this->client->request($request);
        $this->assertSame(200, $response->getStatus(), $path);
        $decoded = json_decode($response->getBody()->buffer(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<mixed>|null
     */
    private function json(string $path): ?array
    {
        $decoded = json_decode($this->client->request(new Request($this->application->url() . $path))->getBody()->buffer(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function testStopEndsOpenStreams(): void
    {
        $stream = $this->client->request(new Request($this->application->url() . '/_peep/stream'));
        $body = $stream->getBody();
        $body->read();

        $reading = async(static function () use ($body): ?string {
            while (($chunk = $body->read()) !== null) {
                // Drain until the server ends the stream.
            }

            return $chunk;
        });

        delay(0.05);
        $this->application->stop();

        $this->assertNull($reading->await());
        $this->application = new Application('127.0.0.1', self::freePort(), new NullLogger());
        $this->application->start();
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        assert(is_resource($server));
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
