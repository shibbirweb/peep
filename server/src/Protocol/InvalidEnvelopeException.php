<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Protocol;

use InvalidArgumentException;

/**
 * The request body is not a valid protocol v1 envelope.
 */
final class InvalidEnvelopeException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
