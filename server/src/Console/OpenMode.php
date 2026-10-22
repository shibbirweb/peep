<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

/**
 * Where to show the viewer after starting: the "Display mode" setting, or --open.
 */
enum OpenMode: string
{
    case Browser = 'browser';
    case Window = 'window';
    case None = 'none';
}
