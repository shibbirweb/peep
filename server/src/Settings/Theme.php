<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Settings;

enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
