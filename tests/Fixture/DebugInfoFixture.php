<?php

namespace ShibbirWeb\Peep\Tests\Fixture;

use RuntimeException;

final class DebugInfoFixture
{
    /**
     * @var string
     */
    private $internal = 'internal';

    /**
     * @var bool
     */
    private $throwing;

    public function __construct(bool $throwing = false)
    {
        $this->throwing = $throwing;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        if ($this->throwing) {
            throw new RuntimeException('Broken __debugInfo');
        }

        return ['shown' => 'from __debugInfo ' . $this->internal];
    }
}
