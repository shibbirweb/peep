<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use Amp\Http\Client\BufferedContent;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Application;

use function Amp\Socket\connect;

/**
 * Mail and SMS reaching the viewer over SMTP and the provider-style HTTP APIs.
 */
final class InboxTest extends TestCase
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

    public function testMailSentOverSmtpLandsInTheInbox(): void
    {
        $port = self::freePort();
        $this->assertNull($this->application->startSmtp($port));

        $socket = connect('tcp://127.0.0.1:' . $port);
        $read = static function () use ($socket): string {
            $reply = '';
            while (! preg_match('/(^|\n)\d{3} [^\n]*\r\n$/', $reply)) {
                $reply .= (string) $socket->read();
            }

            return $reply;
        };

        $this->assertStringStartsWith('220', $read());
        foreach (['EHLO app.test', 'AUTH PLAIN ' . base64_encode("\0shop\0x"), 'MAIL FROM:<shop@app.test>', 'RCPT TO:<ada@example.test>', 'DATA'] as $command) {
            $socket->write($command . "\r\n");
            $read();
        }
        $socket->write("From: Shop <shop@app.test>\r\nTo: ada@example.test\r\nSubject: Welcome\r\n\r\nHello Ada\r\n.\r\n");
        $this->assertStringStartsWith('250', $read());
        $socket->write("QUIT\r\n");
        $read();

        $mail = $this->application->catcher->mails->all()[0] ?? null;
        $this->assertNotNull($mail);
        $this->assertSame('Welcome', $mail->subject);
        $this->assertSame('shop', $mail->project, 'The SMTP username is the project.');
        $this->assertSame('smtp', $mail->source);
    }

    public function testTheMailgunApiAnswersLikeMailgun(): void
    {
        $form = new Form();
        $form->addField('from', 'Shop <shop@app.test>');
        $form->addField('to', 'ada@example.test, bob@example.test');
        $form->addField('subject', 'Invoice');
        $form->addField('html', '<p>Your invoice</p>');
        $form->addField('h:X-Peep-Project', 'shop');
        $form->addField('v:order', '42');
        $form->addStream('attachment', BufferedContent::fromString('%PDF fake', 'application/pdf'), 'invoice.pdf');

        $request = new Request($this->application->url() . '/v3/mg.app.test/messages', 'POST', $form);
        $request->setHeader('authorization', 'Basic ' . base64_encode('api:key-anything'));
        $response = $this->client->request($request);
        $answer = json_decode($response->getBody()->buffer(), true);

        $this->assertSame(200, $response->getStatus());
        $this->assertIsArray($answer);
        $this->assertSame('Queued. Thank you.', $answer['message']);
        $id = $answer['id'];
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^<\d{14}\.[0-9a-f]{16}@mg\.app\.test>$/', $id);

        $mail = $this->application->catcher->mails->all()[0];
        $this->assertSame(['ada@example.test', 'bob@example.test'], $mail->to);
        $this->assertSame('shop', $mail->project);
        $this->assertSame(trim($id, '<>'), $mail->messageId);
        $this->assertSame('invoice.pdf', $mail->attachments[0]['filename']);

        $download = $this->client->request(new Request($this->application->url() . '/_peep/ui/mail/' . $mail->id . '/attachments/0'));
        $this->assertSame('application/octet-stream', $download->getHeader('content-type'));
        $this->assertStringStartsWith('attachment;', (string) $download->getHeader('content-disposition'));
        $this->assertSame('%PDF fake', $download->getBody()->buffer());
    }

    public function testTwilioAndVonageAnswerLikeTheRealApis(): void
    {
        $twilio = new Form();
        $twilio->addField('To', '+15551230001');
        $twilio->addField('From', '+15559990000');
        $twilio->addField('Body', 'Your code is 123456');
        $twilio->addField('StatusCallback', 'http://app.test/twilio/status');

        $request = new Request($this->application->url() . '/2010-04-01/Accounts/AC0123/Messages.json', 'POST', $twilio);
        $request->setHeader('x-peep-project', 'shop');
        $response = $this->client->request($request);
        $resource = json_decode($response->getBody()->buffer(), true);

        $this->assertSame(201, $response->getStatus());
        $this->assertIsArray($resource);
        $this->assertSame('queued', $resource['status']);
        $sid = $resource['sid'];
        $this->assertIsString($sid);
        $this->assertMatchesRegularExpression('/^SM[0-9a-f]{32}$/', $sid);
        $this->assertSame('/2010-04-01/Accounts/AC0123/Messages/' . $sid . '.json', $resource['uri']);
        $this->assertSame('1', $resource['num_segments']);

        $vonage = new Request($this->application->url() . '/sms/json', 'POST', (string) json_encode(['api_key' => 'k', 'api_secret' => 's', 'from' => 'Shop', 'to' => '447700900000', 'text' => str_repeat('x', 200)]));
        $vonage->setHeader('content-type', 'application/json');
        $answer = json_decode($this->client->request($vonage)->getBody()->buffer(), true);

        $this->assertIsArray($answer);
        $this->assertSame('2', $answer['message-count']);
        $this->assertIsArray($answer['messages']);
        $this->assertSame(['0', '0'], array_column($answer['messages'], 'status'));

        $messages = $this->application->catcher->sms->all();
        $this->assertSame(['vonage', 'twilio'], array_map(static fn($sms) => $sms->source, $messages));
        $this->assertSame('shop', $messages[1]->project);
        $this->assertSame('http://app.test/twilio/status', $messages[1]->statusCallback);
    }

    public function testGenericEndpointsAndInboxActions(): void
    {
        $mail = new Request($this->application->url() . '/_peep/mail', 'POST', (string) json_encode(['raw_base64' => base64_encode("From: a@b.c\r\nTo: d@e.f\r\nSubject: Raw\r\nX-Peep-Project: blog\r\n\r\nBody\r\n")]));
        $this->assertSame(202, $this->client->request($mail)->getStatus());

        $sms = new Request($this->application->url() . '/_peep/sms', 'POST', (string) json_encode(['to' => '+1555', 'body' => 'Hi', 'project' => 'blog', 'request_id' => 'req-9']));
        $this->assertSame(202, $this->client->request($sms)->getStatus());

        $caught = $this->application->catcher->mails->all()[0];
        $this->assertSame('blog', $caught->project);
        $this->assertSame('req-9', $this->application->catcher->sms->all()[0]->requestId);

        $source = $this->client->request(new Request($this->application->url() . '/_peep/ui/mail/' . $caught->id . '/source'));
        $this->assertSame('nosniff', $source->getHeader('x-content-type-options'));
        $this->assertStringContainsString('Subject: Raw', $source->getBody()->buffer());

        $read = new Request($this->application->url() . '/_peep/ui/mail/' . $caught->id . '/read', 'POST', '{"read":true}');
        $read->setHeader('x-peep-ui', '1');
        $this->client->request($read)->getBody()->buffer();
        $this->assertTrue($caught->read);

        $clear = new Request($this->application->url() . '/_peep/ui/mail/clear', 'POST', '{"project":null}');
        $clear->setHeader('x-peep-ui', '1');
        $this->client->request($clear)->getBody()->buffer();
        $this->assertSame([], $this->application->catcher->mails->all());
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
