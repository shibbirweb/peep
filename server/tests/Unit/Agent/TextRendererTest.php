<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use ShibbirWeb\PeepServer\Agent\TextRenderer;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

final class TextRendererTest extends TestCase
{
    public function testNodesAreIndentedUnderTheirParent(): void
    {
        $node = [
            'type' => 'object', 'class' => 'App\\Order', 'id' => 1, 'length' => 3, 'children' => [
                ['key' => 'id', 'visibility' => 'public', 'node' => ['type' => 'int', 'value' => 18]],
                ['key' => 'password', 'visibility' => 'private', 'node' => ['type' => 'string', 'value' => '***', 'redacted' => true]],
                ['key' => 'items', 'visibility' => 'public', 'node' => ['type' => 'array', 'length' => 1, 'children' => [
                    ['key' => 0, 'node' => ['type' => 'string', 'value' => 'book', 'length' => 4]],
                ]]],
            ],
        ];

        $this->assertSame(
            "App\\Order #1\n  id: 18\n  password: \"***\" (redacted)\n  items: array(1)\n    0: \"book\"",
            new TextRenderer(0.0)->node($node),
        );
        $this->assertSame('hello', new TextRenderer(0.0)->node(['type' => 'string', 'value' => 'hello']), 'A string on its own is shown as it is.');
        $this->assertSame('array(500) ...', TextRenderer::summary(['type' => 'max_depth', 'length' => 500]));
        $this->assertSame('-> #3', TextRenderer::summary(['type' => 'ref', 'id' => 3]));
    }

    public function testCutChildrenAreCounted(): void
    {
        $node = ['type' => 'array', 'length' => 150, 'truncated' => 149, 'children' => [['key' => 0, 'node' => ['type' => 'null']]]];

        $this->assertSame("array(150)\n  0: null\n  ... 149 more", new TextRenderer(0.0)->node($node));
    }

    public function testBindingsGoIntoTheSqlButNotInsideQuotes(): void
    {
        $this->assertSame(
            "select * from users where name = 'O''Brien' and note = '?' and active = 1 and team_id is NULL",
            TextRenderer::sql("select * from users where name = ? and note = '?' and active = ? and team_id is ?", ["O'Brien", true, null]),
        );
    }

    public function testLaravelPayloadsReadLikeTheViewer(): void
    {
        $renderer = new TextRenderer(0.0, '/app');

        $this->assertSame(
            'query select * from orders where id = 18 (312.4 ms, mysql, SLOW, DUPLICATE, ran 3 times)',
            $renderer->payload(['type' => 'query', 'content' => ['sql' => 'select * from orders where id = ?', 'bindings' => [18], 'time_ms' => 312.4, 'connection' => 'mysql', 'slow' => true, 'duplicate_count' => 3], 'origin' => null]),
        );
        $this->assertSame(
            "job FAILED App\\Jobs\\SendInvoice (queue mail, attempt 3)\n  Mailgun 401",
            $renderer->payload(['type' => 'job', 'content' => ['name' => 'App\\Jobs\\SendInvoice', 'status' => 'failed', 'queue' => 'mail', 'attempts' => 3, 'exception' => 'Mailgun 401'], 'origin' => null]),
        );
        $this->assertSame(
            "http POST https://api.test/pay -> 502 (120 ms)\n  response: Bad gateway",
            $renderer->payload(['type' => 'http', 'content' => ['method' => 'POST', 'url' => 'https://api.test/pay', 'status' => 502, 'time_ms' => 120, 'response_body' => 'Bad gateway'], 'origin' => null]),
        );
        $this->assertSame(
            "EXCEPTION RuntimeException: Payment failed\n  at src/Pay.php:12\n  at App\\Pay::charge() src/Pay.php:12\ncaused by LogicException: No card at src/Card.php:3",
            $renderer->payload(['type' => 'exception', 'content' => [
                'class' => 'RuntimeException', 'message' => 'Payment failed', 'file' => '/app/src/Pay.php', 'line' => 12,
                'frames' => [['file' => '/app/src/Pay.php', 'line' => 12, 'function' => 'charge', 'class' => 'App\\Pay']],
                'previous' => [['class' => 'LogicException', 'message' => 'No card', 'file' => '/app/src/Card.php', 'line' => 3]],
            ], 'origin' => null]),
        );
        $this->assertSame('', $renderer->payload(['type' => 'color', 'content' => ['color' => 'red'], 'origin' => null]));
        $this->assertSame('future {"a":1}', $renderer->payload(['type' => 'future', 'content' => ['a' => 1], 'origin' => null]), 'Unknown types are shown as JSON.');
    }

    public function testACardStartsWithWhereAndWhenItWasMade(): void
    {
        $store = new CardStore();
        $store->apply(new EnvelopeDecoder()->decode((string) json_encode(EnvelopeDecoderTest::envelope())), 100.0);
        $card = $store->find('shop', 'card-1');
        $this->assertNotNull($card);

        $this->assertSame("[card1]  1m 5s ago  POST /users  a.php:3 in store()  red\n  hi", new TextRenderer(165.0, '/app')->card($card));
    }

    public function testAges(): void
    {
        $this->assertSame('4s ago', TextRenderer::ago(4.4));
        $this->assertSame('2m 5s ago', TextRenderer::ago(125));
        $this->assertSame('1h 1m ago', TextRenderer::ago(3700));
    }
}
