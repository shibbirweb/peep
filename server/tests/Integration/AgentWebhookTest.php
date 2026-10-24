<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request as ServerRequest;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Application;
use ShibbirWeb\PeepServer\Tests\Support\JsonPath;
use ShibbirWeb\PeepServer\Webhook\Presets;
use ShibbirWeb\PeepServer\Webhook\Rule;

use function Amp\delay;

/**
 * Agents list webhook rules and calls, and send a rule to an app (a small one in this
 * process), through the /_peep/agent endpoints.
 */
final class AgentWebhookTest extends TestCase
{
    use JsonPath;

    private Application $application;

    private HttpClient $client;

    private SocketHttpServer $app;

    private string $appUrl;

    /**
     * @var list<array{path: string, headers: array<string, string>, body: string}>
     */
    private array $received = [];

    private int $status = 200;

    protected function setUp(): void
    {
        $this->app = SocketHttpServer::createForDirectAccess(new NullLogger());
        $this->app->expose('127.0.0.1:0');
        $this->app->start(new ClosureRequestHandler(function (ServerRequest $request): Response {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = $values[0];
            }
            $this->received[] = ['path' => $request->getUri()->getPath(), 'headers' => $headers, 'body' => $request->getBody()->buffer()];

            return new Response($this->status, ['content-type' => 'application/json'], $this->status === 200 ? '{"ok":true}' : '{"message":"Signature mismatch"}');
        }), new DefaultErrorHandler());
        $this->appUrl = 'http://' . $this->app->getServers()[0]->getAddress()->toString();

        $this->application = new Application('127.0.0.1', self::freePort(), new NullLogger());
        $this->application->start();
        $this->client = HttpClientBuilder::buildDefault();
    }

    protected function tearDown(): void
    {
        $this->application->stop();
        $this->app->stop();
    }

    public function testWithoutRulesTheAgentIsToldWhereTheyAreMade(): void
    {
        $this->assertStringContainsString("No webhook rules yet. The user creates them in the viewer's Webhooks tab", $this->text('GET', '/_peep/agent/webhooks'));

        $answer = $this->send('POST', '/_peep/agent/webhooks/send', '{"event": "delivered"}');
        $this->assertSame(400, $answer->getStatus());
        $this->assertStringContainsString('Say which rule', $answer->getBody()->buffer());
    }

    public function testListsRulesWithoutTheirSecrets(): void
    {
        $this->mailgunRule();

        $text = $this->text('GET', '/_peep/agent/webhooks?project=shop');
        $this->assertStringContainsString('[wh_mailgun] Mailgun events  project shop, on every caught mail', $text);
        $this->assertStringContainsString('POST ' . $this->appUrl . '/webhooks/mailgun  (json, signature mailgun)', $text);
        $this->assertStringContainsString('variables: domain (values not shown)', $text);
        $this->assertStringContainsString('None yet. Send one: peep send-webhook --rule=wh_mailgun', $text);
        $this->assertStringNotContainsString('key-local-signing', $text);
        $this->assertStringNotContainsString('mg.example.test', $text, 'Variable values may be secrets too.');

        $json = $this->json('GET', '/_peep/agent/webhooks?format=json');
        $this->assertSame(['type' => 'mailgun', 'secret_set' => true, 'header' => ''], self::at($json, 'rules', 0, 'signature'));
        $this->assertSame(['domain'], self::at($json, 'rules', 0, 'vars'));
        $this->assertStringNotContainsString('key-local-signing', (string) json_encode($json));

        $this->assertStringContainsString('Rules (0)', $this->text('GET', '/_peep/agent/webhooks?project=blog'));
    }

    public function testSendsOneEventWithTheLastMailAndShowsTheAnswer(): void
    {
        $this->mailgunRule();
        $this->send('POST', '/_peep/mail', (string) json_encode(['project' => 'shop', 'from' => 'shop@app.test', 'to' => ['ada@example.test'], 'subject' => 'Welcome', 'text' => 'Hi']))->getBody()->buffer();
        delay(0.1);
        $this->application->webhooks->clear();
        $this->received = [];

        $answer = $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "mailgun EVENTS", "event": "failed", "mail": "last"}');
        $text = $answer->getBody()->buffer();
        $mail = $this->application->catcher->mails->all()[0];

        $this->assertSame(200, $answer->getStatus(), $text);
        $this->assertStringContainsString('Sent "Mailgun events" event failed with mail ' . $mail->id, $text);
        $this->assertStringContainsString('-> 200 OK in', $text);
        $this->assertStringContainsString("Response body:\n  {\n      \"ok\": true\n  }", $text);
        $this->assertStringContainsString('authorization: (hidden)', $text);
        $this->assertStringNotContainsString('Bearer local', $text);

        $this->assertCount(1, $this->received);
        $payload = json_decode($this->call(0)['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('failed', self::at($payload, 'event-data', 'event'));
        $this->assertSame('ada@example.test', self::at($payload, 'event-data', 'recipient'));
        $timestamp = self::at($payload, 'signature', 'timestamp');
        $token = self::at($payload, 'signature', 'token');
        $this->assertIsString($timestamp);
        $this->assertIsString($token);
        $this->assertSame(hash_hmac('sha256', $timestamp . $token, 'key-local-signing'), self::at($payload, 'signature', 'signature'), "The app's signature check passes.");
    }

    public function testAFailedCallIsAnErrorAndAProblemInTheReport(): void
    {
        $this->mailgunRule();
        $this->status = 401;

        $answer = $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "wh_mailgun", "event": "delivered"}');
        $text = $answer->getBody()->buffer();
        $this->assertSame(502, $answer->getStatus(), 'The CLI exits with 1 and MCP flags the result.');
        $this->assertStringContainsString('with sample data', $text);
        $this->assertStringContainsString('-> 401 in', $text);
        $this->assertStringContainsString('Signature mismatch', $text);

        $list = $this->text('GET', '/_peep/agent/webhooks?project=shop');
        $this->assertStringContainsString('Calls made (1, newest first)', $list, 'Sample data is logged under the rule\'s project.');
        $this->assertStringContainsString('Answer: {"message":"Signature mismatch"}', $list);

        $delivery = $this->application->webhooks->deliveries()[0];
        $full = $this->text('GET', '/_peep/agent/webhooks?id=' . $delivery->id);
        $this->assertStringContainsString('Request body:', $full);
        $this->assertStringContainsString('"event": "delivered"', $full);
        $this->assertSame(404, $this->send('GET', '/_peep/agent/webhooks?id=whd_nope')->getStatus());

        $report = $this->text('GET', '/_peep/agent/report?project=shop');
        $this->assertStringContainsString("### Failed webhook calls\n\n- `POST " . $this->appUrl . '/webhooks/mailgun` -> 401 (Mailgun events: delivered, shop, ' . $delivery->id . ')', $report);
        $this->assertStringContainsString('1 webhook call (1 failed)', $report);
        $this->assertStringContainsString('## Webhook calls', $report);

        $this->assertStringContainsString('webhooks: 1 rules, 1 calls made (1 failed)', $this->text('GET', '/_peep/agent/status'));

        $this->send('POST', '/_peep/agent/clear', '{"project": "blog", "what": "webhooks"}');
        $this->assertCount(1, $this->application->webhooks->deliveries(), 'Only the asked project is cleared.');
        $this->send('POST', '/_peep/agent/clear', '{"project": "shop"}');
        $this->assertSame([], $this->application->webhooks->deliveries(), '"all" includes webhook calls.');
    }

    public function testWithoutAnEventEveryStepRunsOnItsSchedule(): void
    {
        $this->mailgunRule([['event' => 'accepted', 'delay_ms' => 0], ['event' => 'delivered', 'delay_ms' => 200]]);

        $answer = $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "wh_mailgun"}');
        $text = $answer->getBody()->buffer();
        $this->assertSame(202, $answer->getStatus());
        $this->assertStringContainsString('Scheduled 2 calls of "Mailgun events" with sample data: accepted now, delivered after 200 ms.', $text);
        $this->assertStringContainsString('peep webhooks --rule=wh_mailgun --since=', $text);

        delay(0.5);
        $this->assertSame(['delivered', 'accepted'], array_map(static fn($delivery) => $delivery->event, $this->application->webhooks->deliveries()));
    }

    public function testUnknownRulesAndMessagesAreExplained(): void
    {
        $this->mailgunRule();

        $unknown = $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "stripe", "event": "paid"}');
        $this->assertSame(404, $unknown->getStatus());
        $this->assertStringContainsString('No webhook rule "stripe". Rules: wh_mailgun "Mailgun events".', $unknown->getBody()->buffer());

        $this->assertSame(404, $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "wh_mailgun", "project": "blog", "event": "paid"}')->getStatus(), 'The rule is for shop only.');
        $this->assertSame(400, $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "wh_mailgun", "event": "no spaces"}')->getStatus());

        $noMail = $this->send('POST', '/_peep/agent/webhooks/send', '{"rule": "wh_mailgun", "event": "delivered", "mail": "last"}');
        $this->assertSame(404, $noMail->getStatus());
        $this->assertStringContainsString('No mail "last" in project shop in the inbox.', $noMail->getBody()->buffer());
        $this->assertSame([], $this->received, 'Nothing was sent.');
    }

    public function testOtherPagesCannotSend(): void
    {
        $this->mailgunRule();

        $request = new Request($this->application->url() . '/_peep/agent/webhooks/send', 'POST', '{"rule": "wh_mailgun", "event": "delivered"}');
        $request->setHeader('origin', 'https://evil.test');
        $this->assertSame(403, $this->client->request($request)->getStatus());
        $this->assertSame([], $this->received);
    }

    /**
     * @return array{path: string, headers: array<string, string>, body: string}
     */
    private function call(int $index): array
    {
        return $this->received[$index] ?? ['path' => '', 'headers' => [], 'body' => ''];
    }

    /**
     * The Mailgun preset for project shop, calling the app here.
     *
     * @param list<array{event: string, delay_ms: int}>|null $steps
     */
    private function mailgunRule(?array $steps = null): void
    {
        $preset = Presets::all()[0]['rule'];
        $this->application->webhooks->rules->save(Rule::fromArray([
            ...$preset,
            'id' => 'wh_mailgun',
            'name' => 'Mailgun events',
            'project' => 'shop',
            'url' => $this->appUrl . '/webhooks/mailgun',
            'headers' => ['Authorization' => 'Bearer local-app-key'],
            'vars' => ['domain' => 'mg.example.test'],
            'steps' => $steps ?? [['event' => 'delivered', 'delay_ms' => 0]],
        ]));
    }

    /**
     * @param non-empty-string $method
     */
    private function send(string $method, string $path, string $body = ''): \Amp\Http\Client\Response
    {
        $request = new Request($this->application->url() . $path, $method, $body);
        if ($body !== '') {
            $request->setHeader('content-type', 'application/json');
        }

        return $this->client->request($request);
    }

    /**
     * @param non-empty-string $method
     */
    private function text(string $method, string $path): string
    {
        return $this->send($method, $path)->getBody()->buffer();
    }

    /**
     * @param non-empty-string $method
     * @return array<mixed>
     */
    private function json(string $method, string $path): array
    {
        $decoded = json_decode($this->text($method, $path), true);
        $this->assertIsArray($decoded);

        return $decoded;
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
