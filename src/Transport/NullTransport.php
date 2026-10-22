<?php

namespace ShibbirWeb\Peep\Transport;

/**
 * Sends nothing. Used when neither sockets nor curl are available.
 */
final class NullTransport implements TransportInterface
{
    public function post(string $path, string $body): TransportResponse
    {
        return new TransportResponse(204, '');
    }

    public function get(string $path): TransportResponse
    {
        return new TransportResponse(204, '');
    }
}
