<?php

namespace ShibbirWeb\Peep\Support;

use ShibbirWeb\Peep\Peep;

/**
 * Returned by peep()->invade($object): reading a property or calling a method on it
 * shows the private value (or result) and returns it.
 *
 *     peep()->invade($order)->secretTotal;
 *     peep()->invade($order)->calculateDiscount(10);
 */
final class Invader
{
    /**
     * @var object
     */
    private $target;

    /**
     * @var Peep
     */
    private $peep;

    /**
     * @param object $target
     */
    public function __construct($target, Peep $peep)
    {
        $this->target = $target;
        $this->peep = $peep;
    }

    /**
     * @return mixed
     */
    public function __get(string $property)
    {
        $value = PrivateAccess::property($this->target, $property);
        $this->peep->send($value)->label(get_class($this->target) . '::$' . $property);

        return $value;
    }

    /**
     * @param array<mixed> $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        $result = PrivateAccess::call($this->target, $method, $arguments);
        $this->peep->send($result)->label(get_class($this->target) . '::' . $method . '()');

        return $result;
    }
}
