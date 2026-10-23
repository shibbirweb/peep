<?php

namespace ShibbirWeb\Peep\Tests\Unit\Agent;

use ShibbirWeb\Peep\Agent\ViewerApi;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\TransportResponse;

final class ViewerApiTest extends TestCase
{
    /**
     * @var RecordingTransport
     */
    private $transport;

    /**
     * @var list<int>
     */
    private $slept = [];

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
        $this->slept = [];
    }

    public function testFiltersBecomeTheQueryString(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, "[card1] ...\n");

        $result = $this->viewer('/app', 'shop')->cards(['since' => '5m', 'search' => 'order saved', 'limit' => 10, 'json' => '1', 'unknown' => 'x']);

        $this->assertSame(['ok' => true, 'text' => "[card1] ...\n"], $result);
        $this->assertSame(['/_peep/agent/cards?since=5m&search=order%20saved&limit=10&project=shop&base=%2Fapp'], $this->transport->gets);
    }

    public function testAnExplicitProjectWinsOverTheDefault(): void
    {
        $this->viewer(null, 'shop')->report(['project' => 'blog']);
        $this->viewer(null, null)->sms([], true);
        $this->viewer(null, null)->mail([], 'mail_1');

        $this->assertSame([
            '/_peep/agent/report?project=blog',
            '/_peep/agent/sms?format=json',
            '/_peep/agent/mail?id=mail_1',
        ], $this->transport->gets);
    }

    public function testLogsTakeAFileAndALevel(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, '{"entries": []}');
        $this->transport->getResponses[] = new TransportResponse(404, "Say which project: --project=NAME (one of blog, shop).\n");
        $this->transport->getResponses[] = new TransportResponse(404, 'Not found.');

        $this->assertTrue($this->viewer('/app', 'shop')->logs(['since' => '5m'], 'laravel.log', 'error', true)['ok']);
        $this->assertSame(['ok' => false, 'text' => "Say which project: --project=NAME (one of blog, shop).\n"], $this->viewer()->logs([]));
        $this->assertStringContainsString('has no agent endpoints', $this->viewer()->logs([])['text'], 'An older viewer answers plain "Not found.".');

        $this->assertSame([
            '/_peep/agent/logs?since=5m&project=shop&base=%2Fapp&format=json&file=laravel.log&level=error',
            '/_peep/agent/logs',
            '/_peep/agent/logs',
        ], $this->transport->gets);
    }

    public function testAStoppedViewerIsExplained(): void
    {
        $this->transport->failWith = new TransportException('refused');

        $result = $this->viewer()->status();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No Peep viewer is running at 127.0.0.1:23517.', $result['text']);
        $this->assertStringContainsString('Peep desktop app', $result['text']);
    }

    public function testAnOldViewerAndAMissingItemAreTold(): void
    {
        $this->transport->getResponses[] = new TransportResponse(404, '{"error":"not_found","message":"Not found."}');
        $this->transport->getResponses[] = new TransportResponse(404, '{"error":"unknown_mail","message":"No such mail; it may have been deleted."}');
        $this->transport->getResponses[] = new TransportResponse(500, '{"error":"boom","message":"Broken."}');

        $this->assertStringContainsString('has no agent endpoints', $this->viewer()->status()['text']);
        $this->assertSame(['ok' => false, 'text' => "No such mail; it may have been deleted.\n"], $this->viewer()->mail([], 'mail_x'));
        $this->assertSame(['ok' => false, 'text' => "The Peep viewer answered 500: Broken.\n"], $this->viewer()->cards([]));
    }

    public function testWaitPollsFromTheViewersClockUntilCardsCome(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, '{"now": 1000.25}');
        $this->transport->getResponses[] = new TransportResponse(200, '{"now": 1000.5, "cards": []}');
        $this->transport->getResponses[] = new TransportResponse(200, '{"now": 1001, "cards": [{"id": "a", "text": "[a] first"}, {"id": "b", "text": "[b] second"}]}');

        $result = $this->viewer(null, 'shop')->wait([], 10, 2);

        $this->assertSame(['ok' => true, 'text' => "[a] first\n\n[b] second\n"], $result);
        $this->assertSame('/_peep/agent/status?format=json', $this->transport->gets[0]);
        $this->assertSame('/_peep/agent/cards?since=1000.250000&project=shop&format=json', $this->transport->gets[1]);
        $this->assertSame([500], $this->slept);
    }

    public function testWaitGivesUpAfterTheTimeout(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, '{"now": 5}');
        for ($i = 0; $i < 3; $i++) {
            $this->transport->getResponses[] = new TransportResponse(200, '{"cards": []}');
        }

        $result = $this->viewer()->wait([], 1);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No new cards in 1 s.', $result['text']);
        $this->assertSame([500, 500], $this->slept);
    }

    public function testWebhooksTakeARuleAndOneCall(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, '{"rules": []}');
        $this->transport->getResponses[] = new TransportResponse(404, "No webhook call whd_x; the viewer keeps the last 500 calls.\n");

        $this->assertTrue($this->viewer('/app', 'shop')->webhooks(['since' => '5m'], 'Mailgun events', 'whd_1', true)['ok']);
        $this->assertSame(['ok' => false, 'text' => "No webhook call whd_x; the viewer keeps the last 500 calls.\n"], $this->viewer()->webhooks([], null, 'whd_x'));

        $this->assertSame([
            '/_peep/agent/webhooks?since=5m&project=shop&base=%2Fapp&format=json&rule=Mailgun%20events&id=whd_1',
            '/_peep/agent/webhooks?id=whd_x',
        ], $this->transport->gets);
    }

    public function testSendingAWebhookPostsTheRuleAndShowsTheAppsAnswer(): void
    {
        $this->transport->postResponses[] = new TransportResponse(200, "Sent \"Mailgun events\" event delivered with mail mail_1.\n");
        $this->transport->postResponses[] = new TransportResponse(502, "Sent \"Mailgun events\" event delivered with sample data.\n\n[whd_2] ... -> 401 in 3 ms\n");

        $sent = $this->viewer(null, 'shop')->sendWebhook('Mailgun events', 'delivered', 'last');
        $failed = $this->viewer()->sendWebhook('Mailgun events', 'delivered', null, '', 'blog');

        $this->assertSame(['ok' => true, 'text' => "Sent \"Mailgun events\" event delivered with mail mail_1.\n"], $sent);
        $this->assertFalse($failed['ok'], 'The app answered with an error.');
        $this->assertStringContainsString('-> 401 in 3 ms', $failed['text']);
        $this->assertSame([
            ['path' => '/_peep/agent/webhooks/send', 'body' => ['rule' => 'Mailgun events', 'event' => 'delivered', 'mail' => 'last', 'project' => 'shop']],
            ['path' => '/_peep/agent/webhooks/send', 'body' => ['rule' => 'Mailgun events', 'event' => 'delivered', 'project' => 'blog']],
        ], $this->transport->requests);
    }

    public function testClear(): void
    {
        $result = $this->viewer(null, 'shop')->clear(null, 'mail');

        $this->assertSame(['ok' => true, 'text' => "Cleared mail of project shop.\n"], $result);
        $this->assertSame([['path' => '/_peep/agent/clear', 'body' => ['project' => 'shop', 'what' => 'mail']]], $this->transport->requests);

        $this->assertFalse($this->viewer()->clear(null, 'everything')['ok']);
        $this->assertCount(1, $this->transport->requests, 'An unknown kind is refused before asking the viewer.');
    }

    private function viewer(?string $base = null, ?string $project = null): ViewerApi
    {
        $slept = &$this->slept;

        return new ViewerApi($this->transport, '127.0.0.1:23517', $base, $project, function (int $milliseconds) use (&$slept) {
            $slept[] = $milliseconds;
        });
    }
}
