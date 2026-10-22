<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Card;

/**
 * Pause receiving: while paused, envelopes are answered but ignored (no cards, no locks,
 * so a paused peep()->pause() in the app goes on at once). Not saved: a restart resumes.
 */
final class Receiving
{
    public bool $paused = false;
}
