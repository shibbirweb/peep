<?php

namespace ShibbirWeb\Peep;

use ShibbirWeb\Peep\Envelope\Envelope;
use ShibbirWeb\Peep\Envelope\EnvelopeEncoder;
use ShibbirWeb\Peep\Envelope\MetaFactory;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Payload\PayloadInterface;
use ShibbirWeb\Peep\Transport\AvailabilityCache;
use ShibbirWeb\Peep\Transport\TransportInterface;
use Throwable;

/**
 * Sends payloads to the viewer and keeps every failure away from the app.
 */
final class Dispatcher
{
    public const ENVELOPE_PATH = '/_peep/envelope';

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var string
     */
    private $endpoint;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var AvailabilityCache
     */
    private $availability;

    /**
     * @var EnvelopeEncoder
     */
    private $encoder;

    /**
     * @var MetaFactory
     */
    private $metaFactory;

    public function __construct(
        bool $enabled,
        string $endpoint,
        TransportInterface $transport,
        AvailabilityCache $availability,
        EnvelopeEncoder $encoder,
        MetaFactory $metaFactory
    ) {
        $this->enabled = $enabled;
        $this->endpoint = $endpoint;
        $this->transport = $transport;
        $this->availability = $availability;
        $this->encoder = $encoder;
        $this->metaFactory = $metaFactory;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enabled and the viewer is not known to be down. Callers check this before
     * serializing anything, so a closed viewer costs almost nothing.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->availability->isUp($this->endpoint);
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * @param list<PayloadInterface> $payloads
     */
    public function dispatch(string $card, array $payloads): void
    {
        if (! $this->enabled || $payloads === [] || ! $this->availability->isUp($this->endpoint)) {
            return;
        }

        // Socket warnings must not reach the app's error handler (frameworks turn them into exceptions).
        set_error_handler(function () {
            return true;
        });

        try {
            $body = $this->encoder->encode(new Envelope($card, $payloads, $this->metaFactory->create()));

            if ($body !== null) {
                $this->transport->post(self::ENVELOPE_PATH, $body);
                $this->availability->markUp($this->endpoint);
            }
        } catch (TransportException $exception) {
            $this->availability->markDown($this->endpoint);
        } catch (Throwable $exception) {
            // A bug inside Peep must never break the app.
        } finally {
            restore_error_handler();
        }
    }
}
