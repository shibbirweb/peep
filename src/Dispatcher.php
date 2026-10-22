<?php

namespace ShibbirWeb\Peep;

use ShibbirWeb\Peep\Control\Switches;
use ShibbirWeb\Peep\Envelope\Envelope;
use ShibbirWeb\Peep\Envelope\EnvelopeEncoder;
use ShibbirWeb\Peep\Envelope\MetaFactory;
use ShibbirWeb\Peep\Exception\TransportException;
use ShibbirWeb\Peep\Limiter\RateLimiter;
use ShibbirWeb\Peep\Payload\GenericPayload;
use ShibbirWeb\Peep\Payload\PayloadInterface;
use ShibbirWeb\Peep\Support\Uuid;
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

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * @var Switches
     */
    private $switches;

    /**
     * @var string
     */
    private $project;

    public function __construct(
        bool $enabled,
        string $endpoint,
        TransportInterface $transport,
        AvailabilityCache $availability,
        EnvelopeEncoder $encoder,
        MetaFactory $metaFactory,
        string $project = 'default'
    ) {
        $this->enabled = $enabled;
        $this->endpoint = $endpoint;
        $this->transport = $transport;
        $this->availability = $availability;
        $this->encoder = $encoder;
        $this->metaFactory = $metaFactory;
        $this->rateLimiter = new RateLimiter();
        $this->switches = new Switches();
        $this->project = $project;
    }

    /**
     * The viewer's capture switches, refreshed with GET /_peep/switches at most once per TTL
     * when no envelope answer brought fresh values.
     */
    public function switches(): Switches
    {
        if (! $this->switches->isFresh() && $this->isActive()) {
            $answer = $this->getJson('/_peep/switches?project=' . rawurlencode($this->project));

            if ($answer !== null) {
                $this->switches->update($answer);
            } else {
                // Unreachable or old viewer: keep "default" values for a while instead of asking every time.
                $this->switches->update(['switches' => [], 'switches_ttl' => 5]);
            }
        }

        return $this->switches;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
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

        if (! $this->rateLimiter->allow()) {
            if ($this->rateLimiter->shouldReportLimit()) {
                $this->send(Uuid::v4(), [new GenericPayload('notify', ['message' => 'Peep rate limit reached: further calls are not sent.'])]);
            }

            return;
        }

        $this->send($card, $payloads);
    }

    /**
     * GET a viewer endpoint and decode its JSON. Null when the viewer is unreachable,
     * answers with an error, or sends something that is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public function getJson(string $path): ?array
    {
        if (! $this->enabled || ! $this->availability->isUp($this->endpoint)) {
            return null;
        }

        try {
            $response = $this->transport->get($path);
        } catch (TransportException $exception) {
            $this->availability->markDown($this->endpoint);

            return null;
        } catch (Throwable $exception) {
            return null;
        }

        if (! $response->isSuccessful()) {
            return null;
        }

        $data = json_decode($response->body(), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param list<PayloadInterface> $payloads
     */
    private function send(string $card, array $payloads): void
    {
        // No temporary set_error_handler() here: on PHP 7, setting and restoring an error handler
        // while PHP is running one (peep() called from an app's error handler, or from Laravel
        // logging a deprecation) makes PHP drop the app's handler. Transports silence their own
        // socket warnings with @ instead, and everything else is caught below.
        try {
            $body = $this->encoder->encode(new Envelope($card, $payloads, $this->metaFactory->create()));

            if ($body !== null) {
                $response = $this->transport->post(self::ENVELOPE_PATH, $body);
                $this->availability->markUp($this->endpoint);

                $answer = json_decode($response->body(), true);
                if (is_array($answer)) {
                    $this->switches->update($answer);
                }
            }
        } catch (TransportException $exception) {
            $this->availability->markDown($this->endpoint);
        } catch (Throwable $exception) {
            // A bug inside Peep must never break the app.
        }
    }
}
