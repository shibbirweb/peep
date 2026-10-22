<?php

namespace ShibbirWeb\Peep\Tests\Fixture;

use ShibbirWeb\Peep\Config\Config;
use ShibbirWeb\Peep\Serializer\SerializerInterface;
use ShibbirWeb\Peep\Serializer\ValueSerializer;

/**
 * Counts serialize() calls, to prove a disabled Peep does no work.
 */
final class CountingSerializer implements SerializerInterface
{
    /**
     * @var int
     */
    public $calls = 0;

    public function serialize($value): array
    {
        $this->calls++;

        return ValueSerializer::fromConfig(Config::fromArray([]))->serialize($value);
    }
}
