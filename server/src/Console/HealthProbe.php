<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

/**
 * Before starting, checks what already listens on the port: nothing, a Peep viewer, or another program.
 */
final class HealthProbe
{
    /**
     * @phpstan-impure The answer depends on what is running right now.
     */
    public function check(string $host, int $port, float $timeoutSeconds = 0.5): PortStatus
    {
        $address = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;

        $socket = @stream_socket_client("tcp://{$address}:{$port}", $errorCode, $errorMessage, $timeoutSeconds);
        if (! is_resource($socket)) {
            return PortStatus::Free;
        }

        stream_set_timeout($socket, (int) $timeoutSeconds, (int) (fmod($timeoutSeconds, 1) * 1_000_000));
        fwrite($socket, "GET /_peep/health HTTP/1.1\r\nHost: {$address}:{$port}\r\nAccept: application/json\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        [, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $data = json_decode(self::dechunk($response, $body), true);

        return is_array($data) && ($data['app'] ?? null) === 'peep' ? PortStatus::Peep : PortStatus::Other;
    }

    private static function dechunk(string $response, string $body): string
    {
        if (stripos($response, "transfer-encoding: chunked") === false) {
            return $body;
        }

        $decoded = '';
        while (preg_match('/^([0-9a-f]+)\r\n/i', $body, $matches) === 1 && ($size = hexdec($matches[1])) > 0) {
            $decoded .= substr($body, strlen($matches[0]), (int) $size);
            $body = substr($body, strlen($matches[0]) + (int) $size + 2);
        }

        return $decoded;
    }
}
