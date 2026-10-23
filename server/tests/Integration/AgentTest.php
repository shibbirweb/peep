<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Application;
use ShibbirWeb\PeepServer\Tests\Support\JsonPath;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

/**
 * The /_peep/agent endpoints that AI agents and `peep` read, over real HTTP.
 */
final class AgentTest extends TestCase
{
    use JsonPath;

    private Application $application;

    private HttpClient $client;

    protected function setUp(): void
    {
        $this->application = new Application('127.0.0.1', self::freePort(), new NullLogger());
        $this->application->start();
        $this->client = HttpClientBuilder::buildDefault();

        $this->send('POST', '/_peep/envelope', (string) json_encode(EnvelopeDecoderTest::envelope()))->getBody()->buffer();
        $this->send('POST', '/_peep/mail', (string) json_encode(['project' => 'shop', 'from' => 'shop@app.test', 'to' => ['ada@example.test'], 'subject' => 'Welcome', 'text' => 'Hello Ada']))->getBody()->buffer();
        $this->send('POST', '/_peep/sms', (string) json_encode(['project' => 'shop', 'to' => '+15551230001', 'from' => 'Shop', 'body' => 'Code 123456']))->getBody()->buffer();
    }

    protected function tearDown(): void
    {
        $this->application->stop();
    }

    public function testStatusListsProjectsAsTextOrJson(): void
    {
        $text = $this->send('GET', '/_peep/agent/status');
        $this->assertSame('text/plain; charset=utf-8', $text->getHeader('content-type'));
        $body = $text->getBody()->buffer();
        $this->assertStringContainsString('receiving', $body);
        $this->assertStringContainsString('shop: 1 cards', $body);
        $this->assertStringContainsString('1 mail, 1 SMS', $body);

        $json = json_decode($this->send('GET', '/_peep/agent/status?format=json')->getBody()->buffer(), true);
        $this->assertIsArray($json);
        $this->assertSame('peep', $json['app']);
        $this->assertTrue($json['receiving']);
        $this->assertSame([['project' => 'shop', 'cards' => 1, 'last_card_at' => self::at($json, 'projects', 0, 'last_card_at'), 'mails' => 1, 'sms' => 1]], $json['projects']);
        $this->assertIsFloat($json['now']);
    }

    public function testCardsReportMailAndSms(): void
    {
        $cards = $this->send('GET', '/_peep/agent/cards?project=shop&base=/app')->getBody()->buffer();
        $this->assertMatchesRegularExpression('/^\[card1\]  \d+s ago  POST \/users  a\.php:3 in store\(\)  red\n  hi\n$/', $cards);

        $json = json_decode($this->send('GET', '/_peep/agent/cards?format=json')->getBody()->buffer(), true);
        $this->assertIsArray($json);
        $this->assertSame('card-1', self::at($json, 'cards', 0, 'id'));
        $text = self::at($json, 'cards', 0, 'text');
        $this->assertIsString($text);
        $this->assertStringStartsWith('[card1]', $text, 'JSON cards carry their text too.');

        $this->assertSame("No cards match.\n", $this->send('GET', '/_peep/agent/cards?since=' . (microtime(true) + 1))->getBody()->buffer());

        $report = $this->send('GET', '/_peep/agent/report?project=shop');
        $this->assertSame('text/markdown; charset=utf-8', $report->getHeader('content-type'));
        $markdown = $report->getBody()->buffer();
        $this->assertStringStartsWith('# Peep debug report', $markdown);
        $this->assertStringContainsString('## Mail', $markdown);
        $this->assertStringContainsString('Code 123456', $markdown);

        $mail = $this->application->catcher->mails->all()[0];
        $this->assertStringContainsString("Subject: Welcome\n\nHello Ada", $this->send('GET', '/_peep/agent/mail?id=' . $mail->id)->getBody()->buffer());
        $this->assertSame(404, $this->send('GET', '/_peep/agent/mail?id=nope')->getStatus());
        $this->assertStringContainsString('Code 123456', $this->send('GET', '/_peep/agent/sms')->getBody()->buffer());
    }

    public function testClearEmptiesWhatWasAskedFor(): void
    {
        $this->assertSame(400, $this->send('POST', '/_peep/agent/clear', '{"what": "everything"}')->getStatus());

        $this->assertSame(200, $this->send('POST', '/_peep/agent/clear', '{"project": "shop", "what": "cards"}')->getStatus());
        $this->assertSame([], $this->application->cards->cards('shop'));
        $this->assertCount(1, $this->application->catcher->mails->all(), 'Only the cards were asked for.');

        $this->send('POST', '/_peep/agent/clear', '{}');
        $this->assertSame([], $this->application->catcher->mails->all());
        $this->assertSame([], $this->application->catcher->sms->all());
    }

    public function testOtherPagesCannotUseIt(): void
    {
        $request = new Request($this->application->url() . '/_peep/agent/clear', 'POST', '{}');
        $request->setHeader('origin', 'https://evil.test');
        $this->assertSame(403, $this->client->request($request)->getStatus());

        $rebound = new Request($this->application->url() . '/_peep/agent/report');
        $rebound->setHeader('host', 'evil.test');
        $this->assertSame(421, $this->client->request($rebound)->getStatus());
    }

    /**
     * @param non-empty-string $method
     */
    private function send(string $method, string $path, string $body = ''): Response
    {
        $request = new Request($this->application->url() . $path, $method, $body);
        if ($body !== '') {
            $request->setHeader('content-type', 'application/json');
        }

        return $this->client->request($request);
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
