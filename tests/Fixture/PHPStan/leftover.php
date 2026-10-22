<?php

namespace ShibbirWeb\Peep\Tests\Fixture\PHPStan;

function leftover(array $rows): int
{
    peep($rows);
    $count = collect($rows)->peep()->count();
    pd($rows);

    return $count;
}
