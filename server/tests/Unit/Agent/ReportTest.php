<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Agent\CardQuery;
use ShibbirWeb\PeepServer\Agent\Report;
use ShibbirWeb\PeepServer\Agent\TextRenderer;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

final class ReportTest extends TestCase
{
    public function testProblemsComeFirst(): void
    {
        $store = new CardStore();
        $payloads = [
            'exception' => ['class' => 'RuntimeException', 'message' => "Payment\nfailed", 'file' => '/app/src/Pay.php', 'line' => 12, 'frames' => []],
            'job' => ['name' => 'App\\Jobs\\SendInvoice', 'status' => 'failed', 'exception' => 'Mailgun 401'],
            'query' => ['sql' => 'select * from orders where id = ?', 'bindings' => [18], 'time_ms' => 312.4, 'slow' => true, 'duplicate_count' => 4],
            'http' => ['method' => 'POST', 'url' => 'https://api.test/pay', 'status' => 502],
            'log_message' => ['level' => 'error', 'message' => 'Card declined'],
            'request' => ['method' => 'POST', 'uri' => '/orders', 'status' => 500],
        ];
        foreach ($payloads as $type => $content) {
            $store->apply(new EnvelopeDecoder()->decode((string) json_encode(EnvelopeDecoderTest::envelope('card-' . $type, 'shop', [
                ['type' => $type, 'content' => $content, 'origin' => ['file' => '/app/src/Pay.php', 'line' => 12]],
            ]))), 100.0);
        }

        $report = new Report(new TextRenderer(110.0, '/app'))->render($store->cards(), [], [], new CardQuery(project: 'shop'));

        $this->assertStringContainsString("- 1 request, 6 cards\n- 1 query card (1 slow, 1 repeated), 1 exception, 1 failed job\n- 0 mails, 0 SMS", $report);
        $this->assertStringContainsString("### Exceptions\n\n- `RuntimeException: Payment failed` at src/Pay.php:12 (POST /users, card cardexce)", $report);
        $this->assertStringContainsString('- `App\\Jobs\\SendInvoice`: Mailgun 401', $report);
        $this->assertStringContainsString('- `POST /orders` -> 500', $report);
        $this->assertStringContainsString('- `POST https://api.test/pay` -> 502', $report);
        $this->assertStringContainsString('- error: Card declined', $report);
        $this->assertStringContainsString('- 312.4 ms `select * from orders where id = 18`', $report);
        $this->assertStringContainsString('- ran 4 times in one request: `select * from orders where id = 18`', $report);
        $this->assertLessThan(strpos($report, '## Requests'), strpos($report, '## Problems'));
        $this->assertStringContainsString("### POST /users (http, shop, request req1, 10s ago)\n\n```text\n[cardexce]", $report);
    }

    public function testAQuietRunSaysSo(): void
    {
        $report = new Report(new TextRenderer(0.0))->render([], [], [], new CardQuery());

        $this->assertStringContainsString('None found: no exceptions', $report);
        $this->assertStringContainsString('No cards. Check that the app sends to this viewer', $report);
    }
}
