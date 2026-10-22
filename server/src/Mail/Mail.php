<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Mail;

/**
 * One caught mail. The raw MIME is kept, so the source view and attachment downloads
 * always show exactly what the app sent.
 */
final class Mail
{
    public bool $read = false;

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param list<array{name: string, value: string}> $headers
     * @param list<array{index: int, filename: string, content_type: string, size: int, inline: bool}> $attachments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly ?string $requestId,
        public readonly string $messageId,
        public readonly string $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly string $subject,
        public readonly ?string $html,
        public readonly ?string $text,
        public readonly array $headers,
        public readonly array $attachments,
        public readonly string $source,
        public readonly float $receivedAt,
        private readonly string $raw,
    ) {}

    public function raw(): string
    {
        return $this->raw;
    }

    public function size(): int
    {
        return strlen($this->raw);
    }

    /**
     * What the inbox list shows.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $preview = $this->text ?? strip_tags($this->html ?? '');
        $preview = trim((string) preg_replace('/\s+/u', ' ', $preview));

        return [
            'id' => $this->id,
            'project' => $this->project,
            'request_id' => $this->requestId,
            'message_id' => $this->messageId,
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'preview' => mb_substr($preview, 0, 160),
            'attachment_count' => count(array_filter($this->attachments, static fn(array $attachment): bool => ! $attachment['inline'])),
            'size' => $this->size(),
            'source' => $this->source,
            'received_at' => $this->receivedAt,
            'read' => $this->read,
        ];
    }

    /**
     * Everything the detail view shows (not the raw source, which has its own endpoint).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->summary(),
            'html' => $this->html,
            'text' => $this->text,
            'headers' => $this->headers,
            'attachments' => $this->attachments,
        ];
    }
}
