<?php

namespace ShibbirWeb\Peep\Transport;

use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Version;

/**
 * HTTP/1.1 over stream_socket_client(), available on every PHP build without extensions.
 */
final class StreamTransport implements TransportInterface
{
    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var int
     */
    private $connectTimeoutMs;

    /**
     * @var int
     */
    private $timeoutMs;

    public function __construct(string $host, int $port, int $connectTimeoutMs, int $timeoutMs)
    {
        $this->host = $host;
        $this->port = $port;
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->timeoutMs = $timeoutMs;
    }

    public function post(string $path, string $body): TransportResponse
    {
        $deadline = microtime(true) + $this->timeoutMs / 1000;

        $socket = @stream_socket_client(
            'tcp://' . self::addressHost($this->host) . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->connectTimeoutMs / 1000,
            STREAM_CLIENT_CONNECT
        );

        if (! is_resource($socket)) {
            throw new TransportException('Cannot connect to the Peep viewer: ' . $errorMessage);
        }

        try {
            $this->write($socket, $this->request($path, $body), $deadline);
            $raw = $this->read($socket, $deadline);
        } finally {
            fclose($socket);
        }

        return HttpResponseParser::parse($raw);
    }

    private function request(string $path, string $body): string
    {
        return 'POST ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . self::addressHost($this->host) . ':' . $this->port . "\r\n"
            . "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . 'User-Agent: peep-php/' . Version::VERSION . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $data, float $deadline): void
    {
        while ($data !== '') {
            $this->applyTimeout($socket, $deadline);

            $written = @fwrite($socket, $data);
            if (! is_int($written) || $written === 0) {
                throw new TransportException('Writing to the Peep viewer failed.');
            }

            $data = (string) substr($data, $written);
        }
    }

    /**
     * @param resource $socket
     */
    private function read($socket, float $deadline): string
    {
        $response = '';

        while (! feof($socket)) {
            $this->applyTimeout($socket, $deadline);

            $chunk = @fread($socket, 8192);
            $meta = stream_get_meta_data($socket);

            if ($meta['timed_out']) {
                throw new TransportException('The Peep viewer did not answer in time.');
            }

            if (! is_string($chunk)) {
                break;
            }

            $response .= $chunk;
        }

        return $response;
    }

    /**
     * @param resource $socket
     */
    private function applyTimeout($socket, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new TransportException('The Peep viewer did not answer in time.');
        }

        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1000000);

        stream_set_timeout($socket, $seconds, $microseconds);
    }

    /**
     * IPv6 addresses need brackets in a URL and a Host header.
     */
    private static function addressHost(string $host): string
    {
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            return '[' . $host . ']';
        }

        return $host;
    }
}
