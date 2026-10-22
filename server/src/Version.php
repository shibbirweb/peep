<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer;

/**
 * Version of the Peep viewer, reported by the health endpoint.
 */
final class Version
{
    public const string VERSION = '0.0.0';

    private function __construct() {}
}
