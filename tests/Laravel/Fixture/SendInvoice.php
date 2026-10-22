<?php

namespace ShibbirWeb\Peep\Tests\Laravel\Fixture;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class SendInvoice implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @var bool
     */
    public $fail;

    public function __construct(bool $fail = false)
    {
        $this->fail = $fail;
    }

    public function handle(): void
    {
        peep('inside the job');

        if ($this->fail) {
            throw new \RuntimeException('Invoice service down');
        }
    }
}
