<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ShibbirWeb\PeepServer\Event\EventHub;
use ShibbirWeb\PeepServer\Event\ServerEvent;
use ShibbirWeb\PeepServer\Mail\Mail;
use ShibbirWeb\PeepServer\Sms\Sms;
use ShibbirWeb\PeepServer\Webhook\Template\TemplateException;
use ShibbirWeb\PeepServer\Webhook\Template\TemplateRenderer;
use Throwable;

use function Amp\async;
use function Amp\delay;

/**
 * Runs rules: on a caught mail or SMS, from the inbox (manual), or as a test with sample
 * data. Each step is sent after its delay, logged, and published to the UI (WH-06 to WH-10).
 */
final class WebhookRunner
{
    public const int MAX_DELIVERIES = 500;

    public const float TIMEOUT_SECONDS = 10;

    /**
     * Hosts a status callback URL taken from a caught SMS may point at (WH-12). The rule's own
     * URL may point anywhere: the user typed it.
     */
    public const array CALLBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '*.test', '*.localhost'];

    /**
     * @var array<string, Delivery> id => delivery, oldest first
     */
    private array $deliveries = [];

    private readonly HttpClient $client;

    public function __construct(
        public readonly WebhookRules $rules,
        private readonly EventHub $hub,
        ?HttpClient $client = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        // Never follow redirects: a rule calls exactly the URL the user configured.
        $this->client = $client ?? new HttpClientBuilder()->followRedirects(0)->retry(0)->build();
    }

    public function onMail(Mail $mail): void
    {
        foreach ($this->rules->all() as $rule) {
            if ($rule->appliesTo('mail.received', $mail->project, implode(', ', $mail->to))) {
                $this->schedule($rule, $mail, null);
            }
        }
    }

    public function onSms(Sms $sms): void
    {
        foreach ($this->rules->all() as $rule) {
            if ($rule->appliesTo('sms.received', $sms->project, $sms->to)) {
                $this->schedule($rule, null, $sms);
            }
        }
    }

    /**
     * Sends every step of the rule after its delay, or only one event (manual send).
     */
    public function schedule(Rule $rule, ?Mail $mail, ?Sms $sms, ?string $onlyEvent = null): void
    {
        $steps = $onlyEvent !== null ? [['event' => $onlyEvent, 'delay_ms' => 0]] : $rule->steps;

        async(function () use ($rule, $mail, $sms, $steps): void {
            $elapsed = 0;

            foreach ($steps as $step) {
                $wait = $step['delay_ms'] - $elapsed;
                if ($wait > 0) {
                    delay($wait / 1000);
                    $elapsed = $step['delay_ms'];
                }

                $this->deliver($rule, $step['event'], $mail, $sms);
            }
        })->ignore();
    }

    /**
     * Builds, signs, sends and logs one call; retries when the rule says so (WH-09).
     */
    public function deliver(Rule $rule, string $event, ?Mail $mail, ?Sms $sms, int $attempt = 1): Delivery
    {
        $warnings = [];
        $subject = $mail->id ?? $sms->id ?? null;
        $project = $mail->project ?? $sms->project ?? ($rule->project === '*' ? 'default' : $rule->project);

        try {
            $request = $this->prepare($rule, $event, $project, $mail, $sms, $warnings);
        } catch (TemplateException | InvalidRuleException $exception) {
            return $this->record(new Delivery(
                'whd_' . bin2hex(random_bytes(6)),
                $rule->id,
                $rule->name,
                $project,
                $event,
                $subject,
                ['method' => $rule->method, 'url' => $rule->url, 'headers' => [], 'body' => ''],
                null,
                $exception->getMessage(),
                0,
                $warnings,
                $attempt,
                microtime(true),
            ));
        }

        $delivery = $this->send($request, $rule, $event, $project, $subject, $warnings, $attempt);

        $retryable = $delivery->error !== null || ($delivery->response['status'] ?? 0) >= 500;
        if ($rule->retry['enabled'] && $retryable && $attempt < $rule->retry['max']) {
            async(function () use ($rule, $event, $mail, $sms, $attempt): void {
                delay(2 ** ($attempt - 1));
                $this->deliver($rule, $event, $mail, $sms, $attempt + 1);
            })->ignore();
        }

        return $delivery;
    }

    /**
     * Sends a logged request again, exactly as it was (WH-08).
     */
    public function resend(string $id): ?Delivery
    {
        $previous = $this->deliveries[$id] ?? null;

        if ($previous === null || $previous->request['headers'] === []) {
            return null;
        }

        return $this->send($previous->request, null, $previous->event, $previous->project, $previous->subject, [], 1, $previous);
    }

    public function find(string $id): ?Delivery
    {
        return $this->deliveries[$id] ?? null;
    }

    /**
     * Newest first.
     *
     * @return list<Delivery>
     */
    public function deliveries(): array
    {
        return array_reverse(array_values($this->deliveries));
    }

    /**
     * Forgets the logged calls of one project, or of every project.
     */
    public function clear(?string $project = null): void
    {
        $this->deliveries = $project === null ? [] : array_filter($this->deliveries, static fn(Delivery $delivery): bool => $delivery->project !== $project);
        $this->hub->publish([new ServerEvent('webhook.cleared', $project === null ? [] : ['project' => $project])]);
    }

    public static function isAllowedCallbackHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::CALLBACK_HOSTS as $allowed) {
            if ($host === $allowed || ($allowed[0] === '*' && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $warnings
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     *
     * @throws TemplateException
     * @throws InvalidRuleException
     */
    private function prepare(Rule $rule, string $event, string $project, ?Mail $mail, ?Sms $sms, array &$warnings): array
    {
        $variables = Variables::build($rule, $event, $project, $mail, $sms, microtime(true));

        if ($rule->signature['type'] === 'mailgun') {
            $variables['signature'] = Signer::mailgun($rule->signature['secret']);
        }

        $renderer = new TemplateRenderer($variables);

        $url = $renderer->text($rule->url);
        $callback = $sms?->statusCallback;
        if ($rule->useStatusCallback && $callback !== null && $callback !== '') {
            if (! self::isAllowedCallbackHost($callback)) {
                throw new InvalidRuleException('url', 'The status callback ' . $callback . ' points at a host that is not allowed (' . implode(', ', self::CALLBACK_HOSTS) . ').');
            }

            $url = $callback;
        }

        if (preg_match('#^https?://[^\s/]+#i', $url) !== 1) {
            throw new InvalidRuleException('url', 'The rendered URL is not an http(s) URL: ' . $url);
        }

        $fields = [];
        $body = match ($rule->format) {
            'json' => $renderer->json($rule->body !== '' ? $rule->body : '{}'),
            'form' => http_build_query($fields = $renderer->form($rule->body !== '' ? $rule->body : '{}'), '', '&', PHP_QUERY_RFC1738),
            'xml' => $renderer->text($rule->body, xml: true),
            default => $renderer->text($rule->body),
        };

        $headers = ['content-type' => match ($rule->format) {
            'json' => 'application/json',
            'form' => 'application/x-www-form-urlencoded',
            'xml' => 'application/xml',
            default => 'text/plain; charset=utf-8',
        }];

        foreach ($rule->headers as $name => $value) {
            $headers[strtolower($name)] = str_replace(["\r", "\n"], ' ', $renderer->text($value));
        }

        if ($rule->method === 'GET' || $rule->method === 'DELETE') {
            $url .= ($body !== '' ? (str_contains($url, '?') ? '&' : '?') . ($rule->format === 'form' ? $body : 'body=' . rawurlencode($body)) : '');
            $body = '';
            unset($headers['content-type']);
        }

        if ($rule->signature['type'] === 'twilio') {
            $headers['x-twilio-signature'] = Signer::twilio($rule->signature['secret'], $url, $rule->method === 'POST' ? $fields : []);
        } elseif ($rule->signature['type'] === 'custom') {
            $headers[strtolower($rule->signature['header'])] = new TemplateRenderer([...$variables, 'body' => $body])->text($rule->signature['value']);
        }

        array_push($warnings, ...$renderer->warnings);

        return ['method' => $rule->method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param array{method: string, url: string, headers: array<string, string>, body: string} $request
     * @param list<string> $warnings
     */
    private function send(array $request, ?Rule $rule, string $event, string $project, ?string $subject, array $warnings, int $attempt, ?Delivery $previous = null): Delivery
    {
        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $http = new Request($request['url'], $request['method'] !== '' ? $request['method'] : 'POST', $request['body']);
            foreach ($request['headers'] as $name => $value) {
                if ($name !== '') {
                    $http->setHeader($name, $value);
                }
            }
            $http->setTcpConnectTimeout(self::TIMEOUT_SECONDS);
            $http->setTransferTimeout(self::TIMEOUT_SECONDS);
            $http->setInactivityTimeout(self::TIMEOUT_SECONDS);

            $answer = $this->client->request($http);

            $body = '';
            $stream = $answer->getBody();
            while (strlen($body) <= Delivery::MAX_BODY_BYTES && ($chunk = $stream->read()) !== null) {
                $body .= $chunk;
            }

            $headers = [];
            foreach ($answer->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            $response = [
                'status' => $answer->getStatus(),
                'headers' => $headers,
                'body' => substr($body, 0, Delivery::MAX_BODY_BYTES),
                'truncated' => strlen($body) > Delivery::MAX_BODY_BYTES,
            ];
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return $this->record(new Delivery(
            id: 'whd_' . bin2hex(random_bytes(6)),
            ruleId: $rule->id ?? $previous->ruleId ?? '',
            ruleName: $rule->name ?? $previous->ruleName ?? '',
            project: $project,
            event: $event,
            subject: $subject,
            request: $request,
            response: $response,
            error: $error,
            durationMs: (int) round((hrtime(true) - $started) / 1e6),
            warnings: array_values(array_unique($warnings)),
            attempt: $attempt,
            at: microtime(true),
        ));
    }

    private function record(Delivery $delivery): Delivery
    {
        $this->deliveries[$delivery->id] = $delivery;

        while (count($this->deliveries) > self::MAX_DELIVERIES) {
            array_shift($this->deliveries);
        }

        if ($delivery->error !== null) {
            $this->logger->debug('Webhook ' . $delivery->ruleName . ' failed: ' . $delivery->error);
        }

        $this->hub->publish([new ServerEvent('webhook.delivered', $delivery->summary())]);

        return $delivery;
    }
}
