<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Console;

enum PortStatus
{
    case Free;
    case Peep;
    case Other;
}
