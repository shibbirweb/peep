<?php

namespace ShibbirWeb\Peep\Transport;

use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Version;

/**
 * HTTP through ext-curl. Used when stream_socket_client() is disabled.
 */
final class CurlTransport implements TransportInterface
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
        $host = strpos($this->host, ':') !== false && $this->host[0] !== '[' ? '[' . $this->host . ']' : $this->host;

        $handle = curl_init('http://' . $host . ':' . $this->port . $path);
        if ($handle === false) {
            throw new TransportException('curl_init() failed.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
            ],
            CURLOPT_USERAGENT => 'peep-php/' . Version::VERSION,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            // Millisecond timeouts need NOSIGNAL on some builds.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
        ]);

        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);

        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5, so it only runs on PHP 7.
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated
        }

        if (! is_string($responseBody) || $status === 0) {
            throw new TransportException('Cannot reach the Peep viewer: ' . $error);
        }

        return new TransportResponse($status, $responseBody);
    }
}
