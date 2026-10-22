<?php

namespace ShibbirWeb\Peep\Clean;

/**
 * One peep() call left in the code.
 */
final class Finding
{
    /**
     * @var string
     */
    public $file;

    /**
     * @var int
     */
    public $line;

    /**
     * @var string The call as written, shortened.
     */
    public $code;

    /**
     * @var bool Whether --fix can remove it without changing what the code does.
     */
    public $removable;

    public function __construct(string $file, int $line, string $code, bool $removable)
    {
        $this->file = $file;
        $this->line = $line;
        $this->code = $code;
        $this->removable = $removable;
    }
}
