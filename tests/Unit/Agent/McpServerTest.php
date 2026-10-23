<?php

namespace ShibbirWeb\Peep\Tests\Unit\Agent;

use ShibbirWeb\Peep\Agent\McpServer;
use ShibbirWeb\Peep\Agent\ViewerApi;
use ShibbirWeb\Peep\Tests\Fixture\RecordingTransport;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\TransportResponse;
use ShibbirWeb\Peep\Version;

final class McpServerTest extends TestCase
{
    /**
     * @var RecordingTransport
     */
    private $transport;

    /**
     * @var McpServer
     */
    private $server;

    protected function set_up()
    {
        parent::set_up();

        $this->transport = new RecordingTransport();
        $this->server = new McpServer(new ViewerApi($this->transport, '127.0.0.1:23517', '/app', null, function () {
        }));
    }

    public function testInitializeAgreesOnAProtocolVersion(): void
    {
        $answer = $this->ask(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);

        $this->assertSame('2025-03-26', $answer['result']['protocolVersion']);
        $this->assertSame(['name' => 'peep', 'title' => 'Peep PHP debugger', 'version' => Version::VERSION], $answer['result']['serverInfo']);
        $this->assertSame(['tools' => ['listChanged' => false]], $answer['result']['capabilities']);
        $this->assertStringContainsString('peep_report', $answer['result']['instructions']);

        $newer = $this->ask(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2099-01-01']]);
        $this->assertSame(McpServer::PROTOCOL_VERSIONS[0], $newer['result']['protocolVersion'], 'An unknown version gets the newest this server speaks.');
    }

    public function testNotificationsGetNoAnswer(): void
    {
        $this->assertNull($this->server->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
    }

    public function testToolsAreListedWithSchemas(): void
    {
        $answer = $this->ask(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'tools/list']);
        $tools = $answer['result']['tools'];

        $this->assertSame(['peep_status', 'peep_report', 'peep_cards', 'peep_mail', 'peep_sms', 'peep_wait', 'peep_clear'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertNotSame('', $tool['description']);
        }
        $this->assertTrue($tools[6]['annotations']['destructiveHint'], 'Only peep_clear changes anything.');
        $this->assertTrue($tools[1]['annotations']['readOnlyHint']);
    }

    public function testToolCallsAskTheViewer(): void
    {
        $this->transport->getResponses[] = new TransportResponse(200, "# Peep debug report\n");

        $answer = $this->ask(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'peep_report', 'arguments' => ['project' => 'shop', 'since' => '5m', 'limit' => 20]]]);

        $this->assertSame(['content' => [['type' => 'text', 'text' => "# Peep debug report\n"]], 'isError' => false], $answer['result']);
        $this->assertSame(['/_peep/agent/report?project=shop&since=5m&limit=20&base=%2Fapp'], $this->transport->gets);
    }

    public function testAViewerProblemIsAToolError(): void
    {
        $answer = $this->ask(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'peep_status']]);

        $this->assertTrue($answer['result']['isError']);
        $this->assertStringContainsString('has no agent endpoints', $answer['result']['content'][0]['text']);
    }

    public function testClearPostsWhatToClear(): void
    {
        $this->ask(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'peep_clear', 'arguments' => ['project' => 'shop', 'what' => 'cards']]]);

        $this->assertSame([['path' => '/_peep/agent/clear', 'body' => ['project' => 'shop', 'what' => 'cards']]], $this->transport->requests);
    }

    public function testErrors(): void
    {
        $this->assertSame(-32700, $this->decode((string) $this->server->handle('not json'))['error']['code']);
        $this->assertSame(-32600, $this->decode((string) $this->server->handle('[1, 2]'))['error']['code']);
        $this->assertSame(-32601, $this->ask(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])['error']['code']);
        $this->assertSame(-32602, $this->ask(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nope']])['error']['code']);
        $this->assertSame([], (array) $this->ask(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['result']);
    }

    public function testServeAnswersLineByLine(): void
    {
        $input = fopen('php://memory', 'w+');
        $output = fopen('php://memory', 'w+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);
        fwrite($input, "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n\n{\"jsonrpc\":\"2.0\",\"method\":\"notifications/initialized\"}\n{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n");
        rewind($input);

        $this->server->serve($input, $output);

        rewind($output);
        $this->assertSame("{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n{\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{}}\n", stream_get_contents($output));
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function ask(array $message): array
    {
        return $this->decode((string) $this->server->handle((string) json_encode($message)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
