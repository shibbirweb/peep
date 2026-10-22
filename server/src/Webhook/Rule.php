<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

/**
 * A webhook rule: when to call the app back, where, and with what (WH-01).
 */
final readonly class Rule
{
    public const array TRIGGERS = ['mail.received', 'sms.received', 'manual'];

    public const array METHODS = ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'];

    public const array FORMATS = ['json', 'form', 'xml', 'raw'];

    public const array SIGNATURES = ['none', 'mailgun', 'twilio', 'custom'];

    public const int MAX_DELAY_MS = 600000;

    /**
     * @param array<string, string> $headers
     * @param array{type: string, secret: string, header: string, value: string} $signature
     * @param list<array{event: string, delay_ms: int}> $steps
     * @param array{enabled: bool, max: int} $retry
     * @param array<string, string> $vars
     */
    public function __construct(
        public string $id,
        public string $project,
        public string $name,
        public string $trigger,
        public string $toContains,
        public string $url,
        public bool $useStatusCallback,
        public string $method,
        public array $headers,
        public string $format,
        public string $body,
        public array $signature,
        public array $steps,
        public array $retry,
        public array $vars,
        public bool $enabled,
    ) {}

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidRuleException
     */
    public static function fromArray(array $data): self
    {
        $id = self::string($data, 'id');
        $name = trim(self::string($data, 'name'));
        $url = trim(self::string($data, 'url'));
        $trigger = self::string($data, 'trigger', 'manual');
        $method = strtoupper(self::string($data, 'method', 'POST'));
        $format = self::string($data, 'format', 'json');
        $signature = is_array($data['signature'] ?? null) ? $data['signature'] : [];
        $signatureType = self::string($signature, 'type', 'none');
        $retry = is_array($data['retry'] ?? null) ? $data['retry'] : [];

        if ($name === '') {
            throw new InvalidRuleException('name', 'Give the rule a name.');
        }

        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new InvalidRuleException('trigger', 'The trigger must be mail.received, sms.received or manual.');
        }

        // The URL may contain placeholders, but it must be an http(s) URL the user typed (WH-12).
        if (preg_match('#^https?://[^\s/]+#i', $url) !== 1) {
            throw new InvalidRuleException('url', 'The URL must start with http:// or https:// and a host.');
        }

        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidRuleException('method', 'The method must be one of ' . implode(', ', self::METHODS) . '.');
        }

        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidRuleException('format', 'The format must be json, form, xml or raw.');
        }

        if (! in_array($signatureType, self::SIGNATURES, true)) {
            throw new InvalidRuleException('signature', 'The signature must be none, mailgun, twilio or custom.');
        }

        $customHeader = trim(self::string($signature, 'header'));
        if ($signatureType === 'custom' && preg_match('/^[A-Za-z0-9-]+$/', $customHeader) !== 1) {
            throw new InvalidRuleException('signature', 'A custom signature needs a header name.');
        }

        return new self(
            id: preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : 'wh_' . bin2hex(random_bytes(6)),
            project: trim(self::string($data, 'project', '*')) ?: '*',
            name: mb_substr($name, 0, 120),
            trigger: $trigger,
            toContains: trim(self::string(is_array($data['filter'] ?? null) ? $data['filter'] : [], 'to_contains')),
            url: $url,
            useStatusCallback: ($data['use_status_callback'] ?? false) === true,
            method: $method,
            headers: self::headers($data['headers'] ?? []),
            format: $format,
            body: self::string($data, 'body'),
            signature: [
                'type' => $signatureType,
                'secret' => self::string($signature, 'secret'),
                'header' => $customHeader,
                'value' => self::string($signature, 'value'),
            ],
            steps: self::steps($data['steps'] ?? null),
            retry: [
                'enabled' => ($retry['enabled'] ?? false) === true,
                'max' => max(1, min(10, is_int($retry['max'] ?? null) ? $retry['max'] : 3)),
            ],
            vars: self::stringMap($data['vars'] ?? [], 'vars'),
            enabled: ($data['enabled'] ?? true) !== false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project' => $this->project,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'filter' => ['to_contains' => $this->toContains],
            'url' => $this->url,
            'use_status_callback' => $this->useStatusCallback,
            'method' => $this->method,
            'headers' => $this->headers,
            'format' => $this->format,
            'body' => $this->body,
            'signature' => $this->signature,
            'steps' => $this->steps,
            'retry' => $this->retry,
            'vars' => $this->vars,
            'enabled' => $this->enabled,
        ];
    }

    public function appliesTo(string $trigger, string $project, string $to): bool
    {
        return $this->enabled
            && $this->trigger === $trigger
            && ($this->project === '*' || $this->project === $project)
            && ($this->toContains === '' || str_contains(strtolower($to), strtolower($this->toContains)));
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : $default);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(mixed $value): array
    {
        $headers = self::stringMap($value, 'headers');

        foreach (array_keys($headers) as $name) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || in_array(strtolower($name), ['host', 'content-length', 'transfer-encoding', 'connection'], true)) {
                throw new InvalidRuleException('headers', 'Header "' . $name . '" cannot be set.');
            }
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidRuleException($field, ucfirst($field) . ' must be an object of names and texts.');
        }

        $map = [];
        foreach ($value as $name => $text) {
            if (is_string($name) && $name !== '' && (is_string($text) || is_int($text) || is_float($text))) {
                $map[$name] = (string) $text;
            }
        }

        return $map;
    }

    /**
     * @return list<array{event: string, delay_ms: int}>
     */
    private static function steps(mixed $value): array
    {
        $steps = [];

        foreach (is_array($value) ? $value : [] as $step) {
            $event = is_array($step) && is_string($step['event'] ?? null) ? trim($step['event']) : '';
            $delay = is_array($step) && is_int($step['delay_ms'] ?? null) ? $step['delay_ms'] : 0;

            if ($event === '' || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $event) !== 1) {
                throw new InvalidRuleException('steps', 'Each step needs an event name (letters, digits, "_", "-", ".").');
            }

            if ($delay < 0 || $delay > self::MAX_DELAY_MS) {
                throw new InvalidRuleException('steps', 'A step delay must be from 0 to 600,000 ms.');
            }

            $steps[] = ['event' => $event, 'delay_ms' => $delay];
        }

        return $steps !== [] ? array_slice($steps, 0, 20) : [['event' => 'delivered', 'delay_ms' => 0]];
    }
}
