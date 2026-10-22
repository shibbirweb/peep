<?php

namespace ShibbirWeb\Peep\Transport;

use ShibbirWeb\Peep\Exception\TransportException;

/**
 * Parses a raw HTTP/1.x response (status line, headers, body; chunked or not).
 */
final class HttpResponseParser
{
    private function __construct()
    {
    }

    public static function parse(string $raw): TransportResponse
    {
        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false) {
            throw new TransportException('The Peep viewer sent an incomplete response.');
        }

        $head = substr($raw, 0, $separator);
        $body = (string) substr($raw, $separator + 4);
        $lines = explode("\r\n", $head);

        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $lines[0], $matches) !== 1) {
            throw new TransportException('The Peep viewer sent an invalid status line.');
        }

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
            $body = self::decodeChunked($body);
        }

        return new TransportResponse((int) $matches[1], $body);
    }

    private static function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $lineEnd = strpos($body, "\r\n", $offset);
            if ($lineEnd === false) {
                break;
            }

            $sizeLine = substr($body, $offset, $lineEnd - $offset);
            $semicolon = strpos($sizeLine, ';');
            $size = hexdec(trim($semicolon === false ? $sizeLine : substr($sizeLine, 0, $semicolon)));

            if (! is_int($size) || $size <= 0) {
                break;
            }

            $decoded .= substr($body, $lineEnd + 2, $size);
            $offset = $lineEnd + 2 + $size + 2;
        }

        return $decoded;
    }
}
