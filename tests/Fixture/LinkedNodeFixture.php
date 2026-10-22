<?php

namespace ShibbirWeb\Peep\Tests\Fixture;

final class LinkedNodeFixture
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var LinkedNodeFixture|null
     */
    public $next;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
