<?php

namespace ShibbirWeb\Peep\Tests\Unit\Transport;

use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Tests\TestCase;
use ShibbirWeb\Peep\Transport\HttpResponseParser;

final class HttpResponseParserTest extends TestCase
{
    public function testParsesStatusAndBody(): void
    {
        $response = HttpResponseParser::parse("HTTP/1.1 202 Accepted\r\nContent-Type: application/json\r\n\r\n{\"accepted\":true}");

        $this->assertSame(202, $response->status());
        $this->assertSame('{"accepted":true}', $response->body());
        $this->assertTrue($response->isSuccessful());
    }

    public function testDecodesChunkedBodies(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\n{\"a\"\r\n5\r\n:true\r\n1\r\n}\r\n0\r\n\r\n";

        $this->assertSame('{"a":true}', HttpResponseParser::parse($raw)->body());
    }

    public function testErrorStatusesAreResponsesNotExceptions(): void
    {
        $response = HttpResponseParser::parse("HTTP/1.0 400 Bad Request\r\n\r\n");

        $this->assertSame(400, $response->status());
        $this->assertFalse($response->isSuccessful());
    }

    public function testIncompleteResponseThrows(): void
    {
        $this->expectException(TransportException::class);

        HttpResponseParser::parse('HTTP/1.1 202 Accepted');
    }

    public function testInvalidStatusLineThrows(): void
    {
        $this->expectException(TransportException::class);

        HttpResponseParser::parse("SSH-2.0-OpenSSH\r\n\r\n");
    }
}
