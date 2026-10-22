<?php

use ShibbirWeb\Peep\Peep;
use ShibbirWeb\Peep\PeepFactory;

if (! function_exists('peep')) {
    /**
     * Show values in the Peep viewer. Returns the card, so calls chain:
     *
     *     peep($user)->green()->label('Signed in');
     *
     * @param mixed ...$values
     */
    function peep(...$values): Peep
    {
        $peep = PeepFactory::instance()->make();

        if ($values !== []) {
            $peep->send(...$values);
        }

        return $peep;
    }
}
