<?php

namespace ShibbirWeb\Peep\Tests\Laravel\Fixture;

final class OrderShipped
{
    /**
     * @var int
     */
    public $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }
}
