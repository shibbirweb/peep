<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

/**
 * One webhook call as it was made, kept for the delivery log (WH-08).
 */
final class Delivery
{
    public const int MAX_BODY_BYTES = 65536;

    /**
     * @param array{method: string, url: string, headers: array<string, string>, body: string} $request
     * @param array{status: int, headers: array<string, string>, body: string, truncated: bool}|null $response
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $id,
        public readonly string $ruleId,
        public readonly string $ruleName,
        public readonly string $project,
        public readonly string $event,
        public readonly ?string $subject,
        public readonly array $request,
        public readonly ?array $response,
        public readonly ?string $error,
        public readonly int $durationMs,
        public readonly array $warnings,
        public readonly int $attempt,
        public readonly float $at,
    ) {}

    public function ok(): bool
    {
        return $this->response !== null && $this->response['status'] >= 200 && $this->response['status'] < 300;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'project' => $this->project,
            'event' => $this->event,
            'subject' => $this->subject,
            'method' => $this->request['method'],
            'url' => $this->request['url'],
            'status' => $this->response['status'] ?? null,
            'ok' => $this->ok(),
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'warnings' => $this->warnings,
            'attempt' => $this->attempt,
            'at' => $this->at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->summary(), 'request' => $this->request, 'response' => $this->response];
    }
}
