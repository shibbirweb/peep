<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Smtp;

/**
 * What the session answers to one line: text to send (or nothing), whether to close,
 * and a finished message when DATA ended.
 */
final readonly class SmtpReply
{
    private function __construct(
        public ?string $text,
        public bool $close,
        public ?CaughtMessage $message,
    ) {}

    public static function send(string $text, ?CaughtMessage $message = null): self
    {
        return new self($text, false, $message);
    }

    public static function close(string $text): self
    {
        return new self($text, true, null);
    }

    public static function none(): self
    {
        return new self(null, false, null);
    }
}
