<?php

namespace ShibbirWeb\Peep\Tests\Integration;

use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Tests\Fixture\FakeViewer;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\CurlTransport;
use ShibbirWeb\Peep\Transport\StreamTransport;
use ShibbirWeb\Peep\Transport\TransportInterface;

/**
 * Both transports against a real HTTP server (PHP's built-in server running fake-viewer.php).
 */
final class TransportTest extends TestCase
{
    public function testStreamTransportPostsJson(): void
    {
        $this->assertTransportPostsJson(function (int $port) {
            return new StreamTransport('127.0.0.1', $port, 500, 2000);
        });
    }

    public function testCurlTransportPostsJson(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl is not installed.');
        }

        $this->assertTransportPostsJson(function (int $port) {
            return new CurlTransport('127.0.0.1', $port, 500, 2000);
        });
    }

    public function testErrorStatusIsReturnedNotThrown(): void
    {
        $viewer = FakeViewer::start(400);

        try {
            $response = (new StreamTransport('127.0.0.1', $viewer->port(), 500, 2000))->post('/_peep/envelope', '{}');
            $this->assertSame(400, $response->status());
        } finally {
            $viewer->stop();
        }
    }

    public function testStreamTransportThrowsQuicklyWhenNothingListens(): void
    {
        $this->assertThrowsQuicklyWhenNothingListens(new StreamTransport('127.0.0.1', FakeViewer::freePort(), 50, 500));
    }

    public function testCurlTransportThrowsQuicklyWhenNothingListens(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl is not installed.');
        }

        $this->assertThrowsQuicklyWhenNothingListens(new CurlTransport('127.0.0.1', FakeViewer::freePort(), 50, 500));
    }

    /**
     * @param callable(int): TransportInterface $makeTransport
     */
    private function assertTransportPostsJson(callable $makeTransport): void
    {
        $viewer = FakeViewer::start();

        try {
            $transport = $makeTransport($viewer->port());
            $response = $transport->post('/_peep/envelope', '{"protocol":1,"text":"é"}');

            $this->assertSame(202, $response->status());
            $this->assertSame('{"accepted":true}', $response->body());

            $requests = $viewer->requests();
            $this->assertCount(1, $requests);
            $this->assertSame('POST', $requests[0]['method']);
            $this->assertSame('/_peep/envelope', $requests[0]['path']);
            $this->assertSame('application/json', $requests[0]['content_type']);
            $this->assertSame('{"protocol":1,"text":"é"}', $requests[0]['body']);
        } finally {
            $viewer->stop();
        }
    }

    private function assertThrowsQuicklyWhenNothingListens(TransportInterface $transport): void
    {
        $start = microtime(true);

        try {
            $transport->post('/_peep/envelope', '{}');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            $this->assertLessThan(0.5, microtime(true) - $start);
        }
    }
}
