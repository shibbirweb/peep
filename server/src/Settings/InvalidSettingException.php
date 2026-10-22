<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Settings;

use InvalidArgumentException;

final class InvalidSettingException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $setting,
        string $message,
    ) {
        parent::__construct($message);
    }
}
