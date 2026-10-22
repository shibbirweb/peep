<?php

namespace ShibbirWeb\Peep\Tests\Laravel\Fixture;

use Illuminate\Mail\Mailable;

final class InvoiceMail extends Mailable
{
    /**
     * @return $this
     */
    public function build()
    {
        return $this->subject('Invoice ready')->view('peep-invoice-mail', ['number' => 'INV-7']);
    }
}
