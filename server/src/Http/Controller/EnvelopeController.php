<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Lock\LockRegistry;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Protocol\InvalidEnvelopeException;

/**
 * POST /_peep/envelope: receives one envelope from a client.
 */
final readonly class EnvelopeController implements RequestHandler
{
    public const int MAX_BODY_BYTES = 8 * 1024 * 1024;

    /**
     * Switches are fixed to "default" until the switch store arrives (VF-25).
     */
    private const array DEFAULT_SWITCHES = [
        'queries' => 'default',
        'slow_queries' => 'default',
        'duplicate_queries' => 'default',
        'slow_query_ms' => null,
    ];

    public function __construct(
        private EnvelopeDecoder $decoder,
        private CardStore $cards,
        private EventHub $hub,
        private LockRegistry $locks,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $body = $request->getBody();
        $body->increaseSizeLimit(self::MAX_BODY_BYTES);

        try {
            $json = $body->buffer(limit: self::MAX_BODY_BYTES);
        } catch (BufferException|ClientException) {
            return Responses::error(413, 'too_large', 'Envelopes are limited to 8 MB.');
        }

        try {
            $envelope = $this->decoder->decode($json);
        } catch (InvalidEnvelopeException $exception) {
            return Responses::json(400, [
                'error' => $exception->error,
                'message' => $exception->getMessage(),
                'supported' => [EnvelopeDecoder::PROTOCOL],
            ]);
        }

        $now = microtime(true);

        foreach ($envelope->payloads as $payload) {
            $name = $payload->content['name'] ?? null;
            if ($payload->type === 'lock' && is_string($name) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1) {
                $this->locks->register($name, $envelope->project, $envelope->card, $now);
            }
        }

        $this->hub->publish($this->cards->apply($envelope, $now));

        return Responses::json(202, [
            'accepted' => true,
            'switches' => self::DEFAULT_SWITCHES,
            'switches_ttl' => 5,
        ]);
    }
}
