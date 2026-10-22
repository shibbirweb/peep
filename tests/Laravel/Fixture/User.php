<?php

namespace ShibbirWeb\Peep\Tests\Laravel\Fixture;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * Must never be called when the model is shown.
     */
    public function getNameAttribute(?string $value): string
    {
        throw new \LogicException('Accessor called');
    }
}
