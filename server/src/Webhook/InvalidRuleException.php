<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook;

use InvalidArgumentException;

final class InvalidRuleException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
