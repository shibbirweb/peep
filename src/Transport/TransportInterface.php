<?php

namespace ShibbirWeb\Peep\Transport;

use ShibbirWeb\Peep\Exception\TransportException;

/**
 * Sends HTTP requests to the viewer.
 */
interface TransportInterface
{
    /**
     * @param string $path Request path, e.g. "/_peep/envelope".
     * @param string $body JSON body.
     *
     * @throws TransportException When the viewer cannot be reached or the response is broken.
     *                            An HTTP error status is not an exception; it is returned.
     */
    public function post(string $path, string $body): TransportResponse;

    /**
     * @param string $path Request path, e.g. "/_peep/locks/a3f9c2d1".
     *
     * @throws TransportException When the viewer cannot be reached or the response is broken.
     */
    public function get(string $path): TransportResponse;
}
