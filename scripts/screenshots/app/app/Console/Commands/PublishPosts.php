<?php

namespace App\Console\Commands;

class PublishPosts
{
    public function handle(): void
    {
        peep(['published' => 3, 'skipped' => 1])->label('Publish run')->blue();
    }
}
