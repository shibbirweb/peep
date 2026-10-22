<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Http\Controller;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use ShibbirWeb\PeepServer\Card\CardStore;
use ShibbirWeb\PeepServer\Card\Receiving;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Http\Responses;
use ShibbirWeb\PeepServer\Lock\LockRegistry;
use ShibbirWeb\PeepServer\Protocol\EnvelopeDecoder;
use ShibbirWeb\PeepServer\Protocol\InvalidEnvelopeException;
use ShibbirWeb\PeepServer\Switch\SwitchStore;

/**
 * POST /_peep/envelope: receives one envelope from a client.
 */
final readonly class EnvelopeController implements RequestHandler
{
    public const int MAX_BODY_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private EnvelopeDecoder $decoder,
        private CardStore $cards,
        private EventHub $hub,
        private LockRegistry $locks,
        private SwitchStore $switches,
        private Receiving $receiving = new Receiving(),
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

        if ($this->receiving->paused) {
            return Responses::json(202, ['accepted' => false, 'paused' => true, ...$this->switches->answer($envelope->project)]);
        }

        $now = microtime(true);

        foreach ($envelope->payloads as $payload) {
            $name = $payload->content['name'] ?? null;
            if ($payload->type === 'lock' && is_string($name) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1) {
                $this->locks->register($name, $envelope->project, $envelope->card, $now);
            }
        }

        $this->hub->publish($this->cards->apply($envelope, $now));

        return Responses::json(202, ['accepted' => true, ...$this->switches->answer($envelope->project)]);
    }
}
