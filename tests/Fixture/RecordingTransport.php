<?php

namespace ShibbirWeb\Peep\Tests\Fixture;

use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Transport\TransportInterface;
use ShibbirWeb\Peep\Transport\TransportResponse;
use Throwable;

/**
 * Records every request instead of sending it. Can be told to fail.
 */
final class RecordingTransport implements TransportInterface
{
    /**
     * @var list<array{path: string, body: array<string, mixed>}>
     */
    public $requests = [];

    /**
     * @var Throwable|null Thrown on the next post() calls when set.
     */
    public $failWith;

    /**
     * @var bool Raise a PHP warning while posting, like a failing socket call does.
     */
    public $raiseWarning = false;

    /**
     * @var string Body of every envelope answer, e.g. with switches.
     */
    public $postResponseBody = '{"accepted":true}';

    /**
     * @var list<string> Paths requested with GET.
     */
    public $gets = [];

    /**
     * @var list<TransportResponse> Answers for the next GET requests, in order; 404 when empty.
     */
    public $getResponses = [];

    public function post(string $path, string $body): TransportResponse
    {
        if ($this->raiseWarning) {
            trigger_error('Simulated socket warning', E_USER_WARNING);
        }

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new TransportException('Body is not JSON: ' . $body);
        }

        $this->requests[] = ['path' => $path, 'body' => $decoded];

        return new TransportResponse(202, $this->postResponseBody);
    }

    public function get(string $path): TransportResponse
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->gets[] = $path;
        $response = array_shift($this->getResponses);

        return $response !== null ? $response : new TransportResponse(404, '{}');
    }

    /**
     * @return list<array<string, mixed>> Every payload sent, in order.
     */
    public function payloads(): array
    {
        $payloads = [];
        foreach ($this->requests as $request) {
            foreach ($request['body']['payloads'] as $payload) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }
}
