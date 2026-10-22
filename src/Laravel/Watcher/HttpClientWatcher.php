<?php

namespace ShibbirWeb\Peep\Laravel\Watcher;

use ShibbirWeb\Peep\Support\Clock;
use ShibbirWeb\Peep\Support\Uuid;
use Throwable;

/**
 * Outgoing requests made with Laravel's HTTP client (events exist from Laravel 8):
 * one card per request, updated when the response arrives.
 */
final class HttpClientWatcher extends AbstractWatcher
{
    private const SENDING = 'Illuminate\\Http\\Client\\Events\\RequestSending';
    private const RECEIVED = 'Illuminate\\Http\\Client\\Events\\ResponseReceived';
    private const FAILED = 'Illuminate\\Http\\Client\\Events\\ConnectionFailed';

    private const MAX_BODY_BYTES = 65536;

    /**
     * @var array<string, array{card: string, started: int}> request object => card and start time
     */
    private $pending = [];

    public function isSupported(): bool
    {
        return class_exists(self::SENDING);
    }

    public function register($app): void
    {
        $app->make('events')->listen(self::SENDING, function ($event) {
            $this->sending($event->request);
        });

        $app->make('events')->listen(self::RECEIVED, function ($event) {
            $this->finished($event->request, $event->response, null);
        });

        if (class_exists(self::FAILED)) {
            $app->make('events')->listen(self::FAILED, function ($event) {
                $this->finished($event->request, null, 'Connection failed');
            });
        }
    }

    protected function configKey(): string
    {
        return 'send_http_client';
    }

    /**
     * @param \Illuminate\Http\Client\Request $request
     */
    private function sending($request): void
    {
        if (! $this->isOn()) {
            return;
        }

        $key = spl_object_hash($request);
        $this->pending[$key] = ['card' => Uuid::v4(), 'started' => Clock::nanoseconds()];

        $this->send($this->pending[$key]['card'], self::describeRequest($request) + ['status' => null]);
    }

    /**
     * @param \Illuminate\Http\Client\Request $request
     * @param \Illuminate\Http\Client\Response|null $response
     */
    private function finished($request, $response, ?string $error): void
    {
        $key = spl_object_hash($request);
        $pending = isset($this->pending[$key]) ? $this->pending[$key] : null;
        unset($this->pending[$key]);

        if (! $this->isOn()) {
            return;
        }

        $content = self::describeRequest($request);
        $content['status'] = $response !== null ? $response->status() : null;
        $content['error'] = $error;

        if ($response !== null) {
            $content['response_headers'] = self::redact(self::flattenHeaders($response->headers()));
            $content['response_body'] = self::limit((string) $response->body());
        }

        if ($pending !== null) {
            $content['time_ms'] = round((Clock::nanoseconds() - $pending['started']) / 1e6, 2);
        }

        $peep = $this->send($pending !== null ? $pending['card'] : null, $content);

        if ($peep !== null && ($error !== null || $content['status'] >= 400)) {
            $peep->red();
        }
    }

    /**
     * @param array<string, mixed> $content
     */
    private function send(?string $card, array $content): ?\ShibbirWeb\Peep\Peep
    {
        try {
            return $this->peep($card)->payload('http', $content);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @param \Illuminate\Http\Client\Request $request
     * @return array<string, mixed>
     */
    private static function describeRequest($request): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->url(),
            'headers' => self::redact(self::flattenHeaders($request->headers())),
            'body' => self::limit((string) $request->body()),
        ];
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[(string) $name] = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
        }

        return $flat;
    }

    private static function limit(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        return substr($body, 0, self::MAX_BODY_BYTES) . "\n… (" . strlen($body) . ' bytes in total)';
    }
}
