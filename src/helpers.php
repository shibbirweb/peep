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

if (! function_exists('pd')) {
    /**
     * Show values in the Peep viewer, then stop the script (like dd()).
     *
     * @param mixed ...$values
     * @return never
     */
    function pd(...$values): void
    {
        peep(...$values)->die();
    }
}
