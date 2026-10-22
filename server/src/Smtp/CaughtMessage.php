<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Smtp;

final readonly class CaughtMessage
{
    /**
     * @param list<string> $recipients RCPT TO addresses, lower case.
     * @param string|null $username The SMTP login, used as the project.
     */
    public function __construct(
        public string $raw,
        public array $recipients,
        public ?string $username,
    ) {}
}
