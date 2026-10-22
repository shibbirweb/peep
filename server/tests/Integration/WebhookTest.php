<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\FormParser\FormParser;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Mail\MailParser;
use ShibbirWeb\PeepServer\Mail\MimeBuilder;
use ShibbirWeb\PeepServer\Sms\Sms;
use ShibbirWeb\PeepServer\Tests\Support\JsonPath;
use ShibbirWeb\PeepServer\Webhook\Presets;
use ShibbirWeb\PeepServer\Webhook\Rule;
use ShibbirWeb\PeepServer\Webhook\Signer;
use ShibbirWeb\PeepServer\Webhook\WebhookRules;
use ShibbirWeb\PeepServer\Webhook\WebhookRunner;

use function Amp\delay;

/**
 * The runner calling a small app in this process, which checks the signatures the way
 * the providers' own libraries do.
 */
final class WebhookTest extends TestCase
{
    use JsonPath;

    private SocketHttpServer $app;

    private string $appUrl;

    /**
     * @var list<array{path: string, headers: array<string, string>, body: string, fields: array<string, string>}>
     */
    private array $received = [];

    private int $status = 200;

    protected function setUp(): void
    {
        $this->app = SocketHttpServer::createForDirectAccess(new NullLogger());
        $this->app->expose('127.0.0.1:0');
        $this->app->start(new ClosureRequestHandler(function (Request $request): Response {
            $body = $request->getBody()->buffer();
            $fields = [];
            if (str_starts_with((string) $request->getHeader('content-type'), 'application/x-www-form-urlencoded')) {
                foreach (new FormParser()->parseUrlEncodedBody($body)->getValues() as $name => $values) {
                    $fields[$name] = $values[0];
                }
            }

            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = $values[0];
            }

            $this->received[] = ['path' => $request->getUri()->getPath(), 'headers' => $headers, 'body' => $body, 'fields' => $fields];

            return new Response($this->status, ['content-type' => 'text/plain'], 'ok');
        }), new DefaultErrorHandler());

        $this->appUrl = 'http://' . $this->app->getServers()[0]->getAddress()->toString();
    }

    protected function tearDown(): void
    {
        $this->app->stop();
    }

    /**
     * @return array{path: string, headers: array<string, string>, body: string, fields: array<string, string>}
     */
    private function call(int $index): array
    {
        return $this->received[$index] ?? ['path' => '', 'headers' => [], 'body' => '', 'fields' => []];
    }

    public function testAMailgunEventPassesMailgunsSignatureCheck(): void
    {
        $runner = new WebhookRunner(new WebhookRules(), new EventHub());
        $rule = Rule::fromArray([...Presets::all()[0]['rule'], 'url' => $this->appUrl . '/webhooks/mailgun']);
        $mail = new MailParser()->parse(new MimeBuilder()->build('Shop <shop@app.test>', ['Ada <ada@example.test>'], 'Hi', 'Text', null, messageId: 'm1@app.test'), 'shop', [], 'smtp', 1.0);

        $delivery = $runner->deliver($rule, 'delivered', $mail, null);

        $this->assertTrue($delivery->ok(), (string) $delivery->error);
        $payload = json_decode($this->received[0]['body'], true);
        $this->assertIsArray($payload);

        // What Mailgun's docs tell apps to check.
        $timestamp = self::at($payload, 'signature', 'timestamp');
        $token = self::at($payload, 'signature', 'token');
        $this->assertIsString($timestamp);
        $this->assertIsString($token);
        $this->assertSame(hash_hmac('sha256', $timestamp . $token, 'key-local-signing'), self::at($payload, 'signature', 'signature'));
        $this->assertSame('delivered', self::at($payload, 'event-data', 'event'));
        $this->assertSame('ada@example.test', self::at($payload, 'event-data', 'recipient'));
        $this->assertSame('m1@app.test', self::at($payload, 'event-data', 'message', 'headers', 'message-id'));
        $this->assertIsFloat(self::at($payload, 'event-data', 'timestamp'));
        $this->assertSame('application/json', $this->received[0]['headers']['content-type']);
    }

    public function testTwilioStatusCallbacksFollowTheStepsAndPassTwiliosSignatureCheck(): void
    {
        $runner = new WebhookRunner(new WebhookRules(), new EventHub());
        $preset = Presets::all()[1]['rule'];
        $rule = Rule::fromArray([...$preset, 'steps' => [['event' => 'queued', 'delay_ms' => 0], ['event' => 'delivered', 'delay_ms' => 150]]]);

        // The app asked for this callback when it sent the SMS; 127.0.0.1 is allowed.
        $sms = Sms::create('shop', '+15550001', '+15559999', 'Code 123', 'twilio', 1.0, statusCallback: $this->appUrl . '/twilio/status?tenant=7');
        $runner->schedule($rule, null, $sms);

        delay(0.05);
        $this->assertCount(1, $this->received, 'The first step goes at once.');
        delay(0.3);
        $this->assertCount(2, $this->received, 'The second after its delay.');

        foreach ($this->received as $index => $call) {
            $this->assertSame('/twilio/status', $call['path']);
            $this->assertSame(['queued', 'delivered'][$index], $call['fields']['MessageStatus']);
            $this->assertSame($sms->sid, $call['fields']['MessageSid']);

            // What Twilio's RequestValidator computes on the app side.
            $expected = Signer::twilio('your_auth_token', $this->appUrl . '/twilio/status?tenant=7', $call['fields']);
            $this->assertSame($expected, $call['headers']['x-twilio-signature']);
        }

        $this->assertSame(['delivered', 'queued'], array_map(static fn($delivery) => $delivery->event, $runner->deliveries()));
    }

    public function testCallbacksToOtherHostsAreRefusedAndFailuresCanBeRetriedAndResent(): void
    {
        $runner = new WebhookRunner(new WebhookRules(), new EventHub());
        $preset = Presets::all()[1]['rule'];

        $outside = Sms::create('shop', '+1', '+2', 'x', 'twilio', 1.0, statusCallback: 'http://169.254.169.254/latest/meta-data');
        $refused = $runner->deliver(Rule::fromArray($preset), 'sent', null, $outside);
        $this->assertStringContainsString('not allowed', (string) $refused->error);
        $this->assertCount(0, $this->received);

        $this->status = 500;
        $rule = Rule::fromArray([...Presets::all()[3]['rule'], 'url' => $this->appUrl . '/hook', 'retry' => ['enabled' => true, 'max' => 2]]);
        $failed = $runner->deliver($rule, 'delivered', null, null);
        $this->assertSame(500, $failed->response['status'] ?? null);

        delay(1.3);
        $this->assertCount(2, $this->received, 'Retried once after a second.');

        $this->status = 200;
        $resent = $runner->resend($failed->id);
        $this->assertNotNull($resent);
        $this->assertTrue($resent->ok());
        $this->assertSame($failed->request['body'], $this->call(2)['body'], 'Resend sends the same request.');
    }
}
