<?php

namespace App\Http\Controllers;

use RuntimeException;

class CheckoutController
{
    public function store(array $cart)
    {
        peep()->measure();

        peep()->payload('query', ['sql' => 'insert into `orders` (`user_id`, `total`) values (7, 27.5)', 'bindings' => [], 'time_ms' => 812.0, 'connection' => 'mysql', 'driver' => 'mysql', 'slow' => true]);
        peep()->payload('log_message', ['level' => 'warning', 'message' => 'Stock low for Espresso beans (2 left)', 'context' => null])->orange();

        try {
            $this->charge($cart['total']);
        } catch (RuntimeException $exception) {
            peep()->exception($exception);
        }

        peep('Payment captured for order #1042')->green()->label('Payment');

        usleep(120000);
        peep()->measure();
    }

    private function charge(float $total): void
    {
        if ($total > 25) {
            throw new RuntimeException('Card declined: insufficient funds');
        }
    }
}
