<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Tests\Integration;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Application;
use ShibbirWeb\PeepServer\Tests\Support\JsonPath;
use ShibbirWeb\PeepServer\Tests\Unit\Log\LogParserTest;
use ShibbirWeb\PeepServer\Tests\Unit\Protocol\EnvelopeDecoderTest;

/**
 * An app reports its log folder with a card; the Logs tab and agents read the files.
 */
final class LogTest extends TestCase
{
    use JsonPath;

    private Application $application;

    private HttpClient $client;

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/peep-log-test-' . bin2hex(random_bytes(6));
        mkdir($this->folder);
        file_put_contents($this->folder . '/laravel.log', LogParserTest::LARAVEL_LOG);

        $this->application = new Application('127.0.0.1', self::freePort(), new NullLogger());
        $this->application->start();
        $this->client = HttpClientBuilder::buildDefault();

        $envelope = array_replace_recursive(EnvelopeDecoderTest::envelope(), ['meta' => ['logs' => ['path' => $this->folder, 'timezone' => 'UTC']]]);
        $this->request('POST', '/_peep/envelope', (string) json_encode($envelope))->getBody()->buffer();
    }

    protected function tearDown(): void
    {
        $this->application->stop();
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->folder);
    }

    public function testTheReportedFolderListsItsFilesAndEntries(): void
    {
        $folders = $this->json('GET', '/_peep/ui/logs');
        $this->assertSame([['project' => 'shop', 'path' => $this->folder, 'timezone' => 'UTC', 'manual' => false, 'exists' => true]], $folders['folders']);

        $files = $this->json('GET', '/_peep/ui/logs/files?project=shop');
        $this->assertSame('laravel.log', self::at($files, 'files', 0, 'name'));

        $entries = $this->json('GET', '/_peep/ui/logs/entries?project=shop&file=laravel.log');
        $this->assertCount(4, self::list($entries, 'entries'));
        $this->assertSame('App\\Exceptions\\PaymentFailed', self::at($entries, 'entries', 1, 'exception', 'class'));
        $this->assertFalse($entries['reset']);

        file_put_contents($this->folder . '/laravel.log', "[2026-10-20 10:16:00] local.INFO: Later\n", FILE_APPEND);
        $added = $this->json('GET', '/_peep/ui/logs/entries?project=shop&file=laravel.log&after=' . self::int($entries, 'end'));
        $this->assertSame('Later', self::at($added, 'entries', 0, 'message'));
    }

    public function testOnlyTheFoldersLogFilesCanBeRead(): void
    {
        $this->assertSame(404, $this->request('GET', '/_peep/ui/logs/entries?project=shop&file=' . rawurlencode('../../etc/passwd'))->getStatus());
        $this->assertSame(404, $this->request('GET', '/_peep/ui/logs/entries?project=blog&file=laravel.log')->getStatus());
        $this->assertSame(404, $this->request('GET', '/_peep/ui/logs/files?project=blog')->getStatus());
    }

    public function testAFolderCanBeChosenAndGivenBack(): void
    {
        $this->assertSame(403, $this->request('POST', '/_peep/ui/logs/folder', '{"project": "shop", "path": "/tmp"}', ui: false)->getStatus(), 'UI actions need X-Peep-UI.');
        $this->assertSame(422, $this->request('POST', '/_peep/ui/logs/folder', '{"project": "shop", "path": "/no/such/folder"}')->getStatus());

        $chosen = $this->json('POST', '/_peep/ui/logs/folder', (string) json_encode(['project' => 'blog', 'path' => $this->folder . '/']));
        $this->assertSame(['blog', $this->folder, true], [self::at($chosen, 'folders', 0, 'project'), self::at($chosen, 'folders', 0, 'path'), self::at($chosen, 'folders', 0, 'manual')]);

        $this->json('POST', '/_peep/ui/logs/folder', '{"project": "blog", "path": null}');
        $this->assertNull($this->application->logFolders->for('blog'));
    }

    public function testTheFolderPickerListsFolders(): void
    {
        $this->assertSame(403, $this->request('POST', '/_peep/ui/logs/browse', '{"path": "/tmp"}', ui: false)->getStatus(), 'Browsing needs X-Peep-UI.');

        $listing = $this->json('POST', '/_peep/ui/logs/browse', (string) json_encode(['path' => $this->folder]));
        $this->assertSame([realpath($this->folder), 1], [$listing['path'], $listing['logs']]);
        $this->assertSame(realpath(dirname($this->folder)), $listing['parent']);
    }

    public function testAgentsReadLogsAndTheReportListsLogErrors(): void
    {
        $text = $this->request('GET', '/_peep/agent/logs?project=shop&level=error')->getBody()->buffer();
        $this->assertStringContainsString('(1 entries, error and above', $text);
        $this->assertStringContainsString('[2026-10-20 10:15:02] ERROR local: Payment failed', $text);
        $this->assertStringContainsString('App\\Exceptions\\PaymentFailed: Payment failed at /app/app/Pay.php:12', $text);
        $this->assertStringContainsString('"order": 18', $text);

        $this->assertSame(404, $this->request('GET', '/_peep/agent/logs?project=blog')->getStatus());

        $report = $this->request('GET', '/_peep/agent/report?project=shop&since=1792490000')->getBody()->buffer();
        $this->assertStringContainsString("### Log file errors\n\n- ERROR `App\\Exceptions\\PaymentFailed: Payment failed` at /app/app/Pay.php:12 (shop, laravel.log, 2026-10-20 10:15:02)", $report);

        $this->request('POST', '/_peep/agent/clear', '{"project": "shop"}');
        $this->assertStringNotContainsString('Log file errors', $this->request('GET', '/_peep/agent/report?project=shop')->getBody()->buffer(), 'After clear, only newer entries count.');
    }

    /**
     * @param non-empty-string $method
     */
    private function request(string $method, string $path, string $body = '', bool $ui = true): \Amp\Http\Client\Response
    {
        $request = new Request($this->application->url() . $path, $method, $body);
        if ($method === 'POST' && $ui && str_starts_with($path, '/_peep/ui/')) {
            $request->setHeader('x-peep-ui', '1');
        }

        $response = $this->client->request($request);

        return $response;
    }

    /**
     * @param non-empty-string $method
     * @return array<mixed>
     */
    private function json(string $method, string $path, string $body = ''): array
    {
        $decoded = json_decode($this->request($method, $path, $body)->getBody()->buffer(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $data
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? array_values($data[$key]) : [];
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : -1;
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
