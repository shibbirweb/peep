<?php

namespace ShibbirWeb\Peep\Tests\Fixture;

final class VisibilityFixture
{
    /**
     * @var string
     */
    public $visible = 'public value';

    /**
     * @var string
     */
    protected $guarded = 'protected value';

    /**
     * @var string
     */
    private $hidden = 'private value';

    /**
     * @var string
     */
    public $password = 'not to be shown';

    public function hidden(): string
    {
        return $this->hidden;
    }
}
