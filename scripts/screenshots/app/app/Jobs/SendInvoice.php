<?php

namespace App\Jobs;

class SendInvoice
{
    public function handle(): void
    {
        peep()->payload('job', ['name' => 'App\\Jobs\\SendInvoice', 'status' => 'processed', 'queue' => 'mail', 'connection' => 'redis', 'attempts' => 1, 'time_ms' => 38.2]);

        peep()->count('invoices sent');
    }
}
