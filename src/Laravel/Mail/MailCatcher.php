<?php

namespace ShibbirWeb\Peep\Laravel\Mail;

use ShibbirWeb\Peep\PeepFactory;

/**
 * Sends a finished message to the viewer's mail inbox (POST /_peep/mail).
 *
 * The "peep" mailer was chosen on purpose, so this runs even when Peep's cards are
 * turned off. When the viewer is not running, the mail is dropped, as with any
 * local mail catcher that is closed; sending never fails the app.
 */
final class MailCatcher
{
    private function __construct()
    {
    }

    public static function send(PeepFactory $factory, string $raw): void
    {
        $factory->dispatcher()->postJson('/_peep/mail', ['raw_base64' => base64_encode($raw)], true);
    }
}
