<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer;

/**
 * Version of the Peep viewer, reported by the health endpoint.
 */
final class Version
{
    public const string VERSION = '0.1.0-beta.2';

    private function __construct() {}
}
