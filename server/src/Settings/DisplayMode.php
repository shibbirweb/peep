<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Settings;

/**
 * Where the viewer opens when `serve` is run without --open.
 */
enum DisplayMode: string
{
    case Window = 'window';
    case Browser = 'browser';
}
